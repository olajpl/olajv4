<?php
// admin/api/ai_report.php — Olaj V4 (refactor-safe, engines-friendly)
// - Obsługa pojedynczego raportu AI dla kontekstu (domyślnie: order)
// - Obsługa batcha ?type=batch_orders (30 najnowszych zamówień)
// - Fallbacki kolumn (messages.from_client vs direction, order_items.qty vs quantity, itp.)
// - Owner-safe, debug i miękkie logowanie

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php'; // zapewnia logg()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Brak autoryzacji']);
    exit;
}

$DEBUG = isset($_GET['debug']) && (($_GET['debug'] === '1') || ($_GET['debug'] === 'true'));

// ───────────────────────────── Introspection helpers ─────────────────────────────
$_COL_CACHE = [];

$hasColumn = function (PDO $pdo, string $table, string $column) use (&$_COL_CACHE): bool {
    $k = strtolower($table) . '.' . strtolower($column);
    if (array_key_exists($k, $_COL_CACHE)) return $_COL_CACHE[$k];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $st->execute(['c' => $column]);
        $_COL_CACHE[$k] = (bool)$st->fetch(PDO::FETCH_ASSOC);
        return $_COL_CACHE[$k];
    } catch (Throwable $e) {
        $_COL_CACHE[$k] = false;
        return false;
    }
};

$pickColumn = function (PDO $pdo, string $table, array $candidates, ?string $default = null) use ($hasColumn): ?string {
    foreach ($candidates as $c) {
        if ($hasColumn($pdo, $table, $c)) return $c;
    }
    return $default;
};

$safeFetchCol = function (?PDOStatement $st) {
    if (!$st) return null;
    try {
        return $st->fetchColumn();
    } catch (Throwable) {
        return null;
    }
};

// ───────────────────────────── Column choices (fallbacks) ─────────────────────────────
$ordersStatusCol   = $pickColumn($pdo, 'orders', ['order_status', 'status'], 'order_status');
$ordersCreatedCol  = $pickColumn($pdo, 'orders', ['created_at', 'registered_at', 'added_at'], 'created_at');
$ordersUpdatedCol  = $pickColumn($pdo, 'orders', ['updated_at', 'modified_at'], 'updated_at');

$orderItemsQtyCol  = $pickColumn($pdo, 'order_items', ['qty', 'quantity', 'amount'], 'qty');
$orderItemsPriceCol = $pickColumn($pdo, 'order_items', ['unit_price', 'price'], 'unit_price');

$messagesDirCol    = $pickColumn($pdo, 'messages', ['direction'], null);
$messagesFromCol   = $pickColumn($pdo, 'messages', ['from_client'], null);
$messagesAutoCol   = $pickColumn($pdo, 'messages', ['auto_handled'], null);
$messagesParsedCol = $pickColumn($pdo, 'messages', ['parsed'], null);
$messagesConvCol   = $pickColumn($pdo, 'messages', ['conversation_id'], 'conversation_id');
$messagesCtxType   = $pickColumn($pdo, 'messages', ['context_type'], 'context_type');
$messagesCtxId     = $pickColumn($pdo, 'messages', ['context_id'], 'context_id');
$messagesCreated   = $pickColumn($pdo, 'messages', ['created_at', 'added_at'], 'created_at');

$hasAiReports      = $hasColumn($pdo, 'ai_reports', 'data'); // tylko sanity check

// ───────────────────────────── Helpers biz ─────────────────────────────
/** True jeśli rekord wiadomości jest od klienta (IN) */
$isFromClientExpr = (function () use ($messagesDirCol, $messagesFromCol): string {
    if ($messagesDirCol)  return "m1.$messagesDirCol = 'in'";
    if ($messagesFromCol) return "m1.$messagesFromCol = 1";
    // jeśli nie mamy żadnej z kolumn — zwróć coś, co nigdy nie przejdzie (żeby nie fałszować metryki)
    return "1=0";
})();

/** True jeśli wiadomość jest od admina (OUT) */
$isFromAdminExpr = (function () use ($messagesDirCol, $messagesFromCol): string {
    if ($messagesDirCol)  return "m2.$messagesDirCol = 'out'";
    if ($messagesFromCol) return "m2.$messagesFromCol = 0";
    return "1=0";
})();

// ───────────────────────────── Batch mode ─────────────────────────────
$type = (string)($_GET['type'] ?? 'order');
if ($type === 'batch_orders') {
    try {
        $st = $pdo->prepare("SELECT id FROM orders WHERE owner_id = :o ORDER BY $ordersCreatedCol DESC LIMIT 30");
        $st->execute(['o' => $ownerId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Nie mogę pobrać listy zamówień', 'details' => $e->getMessage()]);
        exit;
    }

    $ok = 0;
    $errors = [];
    foreach ($ids as $orderId) {
        try {
            $_GET['type'] = 'order';
            $_GET['id'] = $orderId;
            // wywołaj ten sam endpoint w pamięci (funkcyjnie)
            $res = (function () use ($pdo, $ownerId, $ordersStatusCol, $ordersCreatedCol, $ordersUpdatedCol, $orderItemsQtyCol, $orderItemsPriceCol, $messagesDirCol, $messagesFromCol, $messagesAutoCol, $messagesParsedCol, $messagesConvCol, $messagesCtxType, $messagesCtxId, $messagesCreated, $isFromClientExpr, $isFromAdminExpr, $hasAiReports) {
                return include __FILE__ . '.__single_exec.php';
            })();
            $ok++;
        } catch (Throwable $e) {
            $errors[] = "Zamówienie #$orderId: " . $e->getMessage();
        }
    }
    echo json_encode(['success' => true, 'message' => "Wygenerowano raporty AI dla $ok zamówień.", 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

// ───────────────────────────── Single mode ─────────────────────────────
$contextType = (string)($_GET['type'] ?? 'order');
$contextId   = (int)($_GET['id'] ?? 0);

if (!$contextType || $contextId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak danych wejściowych.']);
    exit;
}

// Dane wyjściowe
$data = [
    'summary'               => '',
    'suggestions'           => [],
    'top_product'           => null,
    'avg_response_time'     => null,
    'unanswered_messages'   => 0,
    'auto_handled_messages' => 0,
    'avg_fulfillment_time'  => null,
];

// ─── Najczęściej kupowany produkt (po nazwie — z fallbackiem qty/quantity) ───
try {
    $sqlTop = "
        SELECT p.name, SUM(oi.$orderItemsQtyCol) AS total
        FROM order_items oi
        JOIN order_groups og ON og.id = oi.order_group_id
        JOIN orders o ON o.id = og.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.owner_id = :owner_id AND o.id = :order_id
        GROUP BY p.name
        ORDER BY total DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sqlTop);
    $st->execute(['owner_id' => $ownerId, 'order_id' => $contextId]);
    $top = $st->fetch(PDO::FETCH_ASSOC);
    $data['top_product'] = $top['name'] ?? null;
} catch (Throwable $e) {
    // brak top_product nie blokuje raportu
}

// ─── Metryki wiadomości (avg response, unanswered, auto handled) ───
try {
    // Średni czas odpowiedzi — m1 (klient) -> m2 (admin) w tej samej rozmowie
    $sqlResp = "
        SELECT m1.$messagesCreated AS client_time, MIN(m2.$messagesCreated) AS admin_time
        FROM messages m1
        LEFT JOIN messages m2
               ON m2.$messagesConvCol = m1.$messagesConvCol
              AND m2.$messagesCreated  > m1.$messagesCreated
              AND ($isFromAdminExpr)
        WHERE ($isFromClientExpr)
          AND m1.owner_id = :owner_id
          AND m1.$messagesCtxId = :ctx_id
          AND m1.$messagesCtxType = :ctx_type
        GROUP BY m1.id
    ";
    $st = $pdo->prepare($sqlResp);
    $st->execute(['owner_id' => $ownerId, 'ctx_id' => $contextId, 'ctx_type' => $contextType]);

    $diffs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['admin_time'])) {
            $diffs[] = strtotime((string)$row['admin_time']) - strtotime((string)$row['client_time']);
        }
    }
    if ($diffs) {
        $avg = array_sum($diffs) / max(1, count($diffs));
        $data['avg_response_time'] = gmdate('H:i:s', (int)$avg);
    } else {
        $data['avg_response_time'] = 'Brak danych';
    }

    // Wiadomości bez odpowiedzi w ramach kontekstu
    $sqlUnans = "
        SELECT COUNT(*)
        FROM messages m1
        WHERE ($isFromClientExpr)
          AND m1.owner_id = :owner_id
          AND m1.$messagesCtxId = :ctx_id
          AND m1.$messagesCtxType = :ctx_type
          AND NOT EXISTS (
              SELECT 1 FROM messages m2
              WHERE m2.$messagesConvCol = m1.$messagesConvCol
                AND m2.$messagesCreated > m1.$messagesCreated
                AND ($isFromAdminExpr)
          )
    ";
    $st = $pdo->prepare($sqlUnans);
    $st->execute(['owner_id' => $ownerId, 'ctx_id' => $contextId, 'ctx_type' => $contextType]);
    $data['unanswered_messages'] = (int)$st->fetchColumn();

    // Auto handled
    if ($messagesAutoCol && $messagesParsedCol) {
        $sqlAuto = "
            SELECT COUNT(*) FROM messages
            WHERE owner_id = :owner_id
              AND $messagesParsedCol = 1
              AND $messagesAutoCol = 1
              AND $messagesCtxId = :ctx_id
              AND $messagesCtxType = :ctx_type
        ";
        $st = $pdo->prepare($sqlAuto);
        $st->execute(['owner_id' => $ownerId, 'ctx_id' => $contextId, 'ctx_type' => $contextType]);
        $data['auto_handled_messages'] = (int)$st->fetchColumn();
    } else {
        $data['auto_handled_messages'] = 0;
    }
} catch (Throwable $e) {
    // brak metryk wiadomości nie jest krytyczny
    if ($DEBUG && function_exists('logg')) {
        logg('warning', 'ai.report', 'messages_metrics_error', ['err' => $e->getMessage()]);
    }
}

// ─── Fulfillment time (czas od stworzenia do zrealizowania) ───
try {
    // Status „zrealizowane” wg enum engine może być różnie trzymany:
    // - orders.order_status = 'zrealizowane' (PL) lub 'completed' (EN)
    // - możesz dopasować tutaj wartości z Engine\Enum\OrderStatus, jeśli masz je w projekcie
    $targetStatuses = ['zrealizowane', 'completed', 'fulfilled']; // fallback lista

    $placeholders = implode(',', array_fill(0, count($targetStatuses), '?'));
    $params = array_merge([$ownerId, $contextId], $targetStatuses);

    $sqlFulfill = "
        SELECT TIMESTAMPDIFF(SECOND, $ordersCreatedCol, $ordersUpdatedCol) AS seconds
        FROM orders
        WHERE owner_id = ?
          AND id = ?
          AND $ordersStatusCol IN ($placeholders)
        LIMIT 1
    ";
    $st = $pdo->prepare($sqlFulfill);
    $st->execute($params);
    $sec = (int)($st->fetchColumn() ?: 0);
    if ($sec > 0) $data['avg_fulfillment_time'] = gmdate('H:i:s', $sec);
} catch (Throwable $e) {
    // pomijamy
}

// ─── Sugestie ───
if (($data['unanswered_messages'] ?? 0) > 0) {
    $data['suggestions'][] = "Odpowiedz na {$data['unanswered_messages']} wiadomości bez odpowiedzi.";
}
if (!empty($data['avg_response_time']) && $data['avg_response_time'] !== 'Brak danych') {
    $data['suggestions'][] = "Średni czas odpowiedzi to {$data['avg_response_time']} – postaraj się skrócić.";
}
if (($data['auto_handled_messages'] ?? 0) > 0) {
    $data['suggestions'][] = "{$data['auto_handled_messages']} wiadomości zostało obsłużonych automatycznie przez system.";
}
if (!empty($data['top_product'])) {
    $data['suggestions'][] = "Najczęściej kupowany produkt: {$data['top_product']}.";
}

// ─── Persist (ai_reports) ───
try {
    if ($hasAiReports) {
        $st = $pdo->prepare("
            INSERT INTO ai_reports (owner_id, context_type, context_id, data, created_at)
            VALUES (:owner_id, :type, :id, :data, NOW())
        ");
        $st->execute([
            'owner_id' => $ownerId,
            'type'     => $contextType,
            'id'       => $contextId,
            'data'     => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
} catch (Throwable $e) {
    if ($DEBUG && function_exists('logg')) {
        logg('warning', 'ai.report', 'persist_error', ['err' => $e->getMessage()]);
    }
}

// ─── Output ───
$out = ['success' => true, 'data' => $data];
if ($DEBUG) {
    $out['_debug'] = [
        'orders' => [
            'status_col'  => $ordersStatusCol,
            'created_col' => $ordersCreatedCol,
            'updated_col' => $ordersUpdatedCol,
        ],
        'order_items' => [
            'qty_col'   => $orderItemsQtyCol,
            'price_col' => $orderItemsPriceCol,
        ],
        'messages' => [
            'direction'  => $messagesDirCol,
            'from_client' => $messagesFromCol,
            'auto_col'   => $messagesAutoCol,
            'parsed_col' => $messagesParsedCol,
            'created'    => $messagesCreated,
        ],
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
