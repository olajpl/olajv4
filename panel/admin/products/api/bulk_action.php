<?php
// admin/products/api/bulk_action.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

// TYLKO POST + JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'invalid_method']);
    exit;
}

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$action = (string)($in['action'] ?? '');
$ids    = $in['ids'] ?? [];
$data   = (array)($in['data'] ?? []);
if (!$action || !is_array($ids) || count($ids) === 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'action_or_ids_missing']);
    exit;
}

// Helper: dynamiczne IN (...)
function bindIn(PDO $pdo, array $ids, string $prefix = ':id'): array {
    $params = [];
    $ph = [];
    foreach (array_values($ids) as $i => $id) {
        $k = $prefix.$i;
        $ph[] = $k;
        $params[$k] = (int)$id;
    }
    return [$ph, $params];
}

// Czy kolumna istnieje?
function colExists(PDO $pdo, string $table, string $col): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $col]);
    return (bool)$st->fetchColumn();
}

// Start
$pdo->beginTransaction();
try {
    [$ph, $idParams] = bindIn($pdo, $ids);
    $common = ['owner_id' => $ownerId] + $idParams;

    switch ($action) {
        case 'activate':
        case 'deactivate': {
            $val = $action === 'activate' ? 1 : 0;
            if (!colExists($pdo, 'products', 'active')) {
                throw new RuntimeException('col_active_missing');
            }
            $sql = "UPDATE products SET active = :val WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':val' => $val, ':owner' => $ownerId] + $idParams);
            break;
        }

        case 'delete_soft': {
            if (!colExists($pdo, 'products', 'deleted_at')) {
                throw new RuntimeException('col_deleted_at_missing');
            }
            $sql = "UPDATE products SET deleted_at = NOW() WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':owner' => $ownerId] + $idParams);
            break;
        }

        case 'price_set': {
            if (!colExists($pdo, 'products', 'unit_price')) {
                throw new RuntimeException('col_unit_price_missing');
            }
            $price = (float)($data['price'] ?? null);
            if ($price < 0) $price = 0;
            $sql = "UPDATE products SET unit_price = :p WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':p' => $price, ':owner' => $ownerId] + $idParams);
            break;
        }

        case 'price_change_pct': {
            if (!colExists($pdo, 'products', 'unit_price')) {
                throw new RuntimeException('col_unit_price_missing');
            }
            $pct = (float)($data['pct'] ?? 0);
            // np. +10 → *1.10,  -5 → *0.95
            $factor = 1 + ($pct / 100.0);
            if ($factor < 0) $factor = 0;
            $sql = "UPDATE products
                    SET unit_price = ROUND(COALESCE(unit_price,0) * :f, 2)
                    WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':f' => $factor, ':owner' => $ownerId] + $idParams);
            break;
        }

        case 'vat_set': {
            if (!colExists($pdo, 'products', 'vat_rate')) {
                throw new RuntimeException('col_vat_rate_missing');
            }
            $vat = (float)($data['vat'] ?? 23);
            if ($vat < 0) $vat = 0;
            $sql = "UPDATE products SET vat_rate = :v WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':v' => $vat, ':owner' => $ownerId] + $idParams);
            break;
        }

        case 'stock_adjust': {
            // pracujemy na stock_cached (masz takie pole w schemacie)
            if (!colExists($pdo, 'products', 'stock_cached')) {
                throw new RuntimeException('col_stock_cached_missing');
            }
            $delta = (float)($data['delta'] ?? 0);
            $sql = "UPDATE products
                    SET stock_cached = stock_cached + :d
                    WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':d' => $delta, ':owner' => $ownerId] + $idParams);
            break;
        }

        case 'category_set': {
            if (!colExists($pdo, 'products', 'category_id')) {
                throw new RuntimeException('col_category_id_missing');
            }
            $cat = $data['category_id'] ?? null;
            $cat = ($cat === '' || $cat === null) ? null : (int)$cat;
            $sql = "UPDATE products
                    SET category_id = :c
                    WHERE owner_id = :owner AND id IN (".implode(',', $ph).")";
            $st  = $pdo->prepare($sql);
            $st->execute([':c' => $cat, ':owner' => $ownerId] + $idParams);
            break;
        }

        default:
            throw new RuntimeException('unknown_action');
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
