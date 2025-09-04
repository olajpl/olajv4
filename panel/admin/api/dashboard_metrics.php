<?php
// admin/api/dashboard_metrics.php — Olaj V4 (refactor-safe, engines-friendly)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php'; // miękkie: logg()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

$days = max(7, min(90, (int)($_GET['days'] ?? 30)));
$DEBUG = isset($_GET['debug']) && (($_GET['debug'] === '1') || ($_GET['debug'] === 'true'));

// ───────────────────────────────── Time window (server TZ) ─────────────────────────────────
$fromDT = new DateTime('today -' . ($days - 1) . ' days');
$toDT   = new DateTime('tomorrow');

$from = $fromDT->format('Y-m-d 00:00:00');
$to   = $toDT->format('Y-m-d 00:00:00');

$todayStart     = (new DateTime('today'))->format('Y-m-d 00:00:00');
$tomorrow       = (new DateTime('tomorrow'))->format('Y-m-d 00:00:00');
$yesterdayStart = (new DateTime('yesterday'))->format('Y-m-d 00:00:00');

// ───────────────────────────── Helpers: schema introspection ─────────────────────────────
/** Cache dla SHOW COLUMNS */
$_HAS_COL_CACHE = [];

/** Sprawdza czy tabela posiada kolumnę (prosty cache). */
$hasColumn = function (PDO $pdo, string $table, string $column) use (&$_HAS_COL_CACHE): bool {
  $key = strtolower($table) . '.' . strtolower($column);
  if (array_key_exists($key, $_HAS_COL_CACHE)) return $_HAS_COL_CACHE[$key];
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
    $st->execute(['col' => $column]);
    $_HAS_COL_CACHE[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    return $_HAS_COL_CACHE[$key];
  } catch (Throwable $e) {
    return $_HAS_COL_CACHE[$key] = false;
  }
};

/** Wygodny wybór pierwszej istniejącej kolumny. */
$pickColumn = function (PDO $pdo, string $table, array $candidates) use ($hasColumn): ?string {
  foreach ($candidates as $c) {
    if ($hasColumn($pdo, $table, $c)) return $c;
  }
  return null;
};

// ──────────────────────────────── Column fallbacks ────────────────────────────────
$ordersCreatedCol  = $pickColumn($pdo, 'orders',        ['created_at', 'registered_at', 'added_at', 'date_add']) ?? 'created_at';
$orderGroupsDone   = $hasColumn($pdo, 'order_groups',    'checkout_completed');
$orderGroupsStatus = $hasColumn($pdo, 'order_groups',    'status');
$itemsQtyCol       = $pickColumn($pdo, 'order_items',   ['qty', 'quantity', 'amount']) ?? 'qty';
$itemsPriceCol     = $pickColumn($pdo, 'order_items',   ['unit_price', 'price']) ?? 'unit_price';
$paymentsStatusCol = $pickColumn($pdo, 'payments',      ['status']) ?? 'status';

// ───────────────────────────────── SQL helpers ─────────────────────────────────
/** Próbuj wykonać kwerendę – w razie błędu zwróć null (kontynuujemy fallbacki). */
$tryQuery = function (PDO $pdo, string $sql, array $params): ?PDOStatement {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
  } catch (Throwable $e) {
    return null;
  }
};

// ───────────────────────────── KPI: orders + sales (fallbacki) ─────────────────────────────
$salesBetween = function (PDO $pdo, int $ownerId, string $from, string $to) use (
  $ordersCreatedCol,
  $orderGroupsDone,
  $orderGroupsStatus,
  $itemsQtyCol,
  $itemsPriceCol,
  $tryQuery
): float {
  $params = ['owner_id' => $ownerId, 'from' => $from, 'to' => $to];

  // bazowa projekcja wartości (oi.price fallback)
  $valueExpr = "COALESCE(oi.$itemsPriceCol, p.price, 0) * COALESCE(oi.$itemsQtyCol, 0)";

  // v1: og.checkout_completed=1 (boolean)
  if ($orderGroupsDone) {
    $q1 = "
            SELECT SUM($valueExpr)
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            JOIN orders o ON o.id = og.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE o.owner_id = :owner_id
              AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
              AND og.checkout_completed = 1
        ";
    if ($st = $tryQuery($pdo, $q1, $params)) return (float)($st->fetchColumn() ?: 0);
  }

  // v2: og.status = 'checkout_completed' (enum)
  if ($orderGroupsStatus) {
    $q2 = "
            SELECT SUM($valueExpr)
            FROM order_items oi
            JOIN order_groups og ON og.id = oi.order_group_id
            JOIN orders o ON o.id = og.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE o.owner_id = :owner_id
              AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
              AND og.status = 'checkout_completed'
        ";
    if ($st = $tryQuery($pdo, $q2, $params)) return (float)($st->fetchColumn() ?: 0);
  }

  // v3: jakiekolwiek płatności zasilone (=paid/confirmed/authorized)
  $q3 = "
        SELECT SUM($valueExpr)
        FROM order_items oi
        JOIN order_groups og ON og.id = oi.order_group_id
        JOIN orders o ON o.id = og.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.owner_id = :owner_id
          AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
          AND EXISTS(
                SELECT 1 FROM payments pay
                WHERE pay.order_group_id = og.id
                  AND pay.status IN ('paid','confirmed','authorized')
          )
    ";
  if ($st = $tryQuery($pdo, $q3, $params)) return (float)($st->fetchColumn() ?: 0);

  // v4: total bez warunku finalizacji (ostatnia szansa)
  $q4 = "
        SELECT SUM($valueExpr)
        FROM order_items oi
        JOIN order_groups og ON og.id = oi.order_group_id
        JOIN orders o ON o.id = og.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.owner_id = :owner_id
          AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
    ";
  $st = $pdo->prepare($q4);
  $st->execute($params);
  return (float)($st->fetchColumn() ?: 0);
};

$ordersBetween = function (PDO $pdo, int $ownerId, string $from, string $to) use ($ordersCreatedCol): int {
  $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE owner_id = :owner_id AND $ordersCreatedCol >= :from AND $ordersCreatedCol < :to");
  $st->execute(['owner_id' => $ownerId, 'from' => $from, 'to' => $to]);
  return (int)$st->fetchColumn();
};

// KPI: dziś / wczoraj
$orders_today     = $ordersBetween($pdo, $ownerId, $todayStart, $tomorrow);
$orders_yesterday = $ordersBetween($pdo, $ownerId, $yesterdayStart, $todayStart);
$sales_today      = $salesBetween($pdo, $ownerId, $todayStart, $tomorrow);
$sales_yesterday  = $salesBetween($pdo, $ownerId, $yesterdayStart, $todayStart);

// ───────────────────────── Oś dni dla wykresów (labels) ─────────────────────────
$labels = [];
$cursor = clone $fromDT;
while ($cursor < $toDT) {
  $labels[] = $cursor->format('Y-m-d');
  $cursor->modify('+1 day');
}

// ───────────────────────────── Serie sprzedaży (fallbacki) ─────────────────────────────
$salesDaily = (function () use (
  $pdo,
  $ownerId,
  $from,
  $to,
  $ordersCreatedCol,
  $orderGroupsDone,
  $orderGroupsStatus,
  $itemsQtyCol,
  $itemsPriceCol
) {
  $valueExpr = "COALESCE(oi.$itemsPriceCol, p.price, 0) * COALESCE(oi.$itemsQtyCol, 0)";
  $base = "
        FROM order_items oi
        JOIN order_groups og ON og.id = oi.order_group_id
        JOIN orders o ON o.id = og.order_id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.owner_id = :owner_id
          AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
    ";

  $tries = [];

  if ($orderGroupsDone) {
    $tries[] = "SELECT DATE(o.$ordersCreatedCol) d, SUM($valueExpr) v $base AND og.checkout_completed = 1 GROUP BY DATE(o.$ordersCreatedCol) ORDER BY d";
  }
  if ($orderGroupsStatus) {
    $tries[] = "SELECT DATE(o.$ordersCreatedCol) d, SUM($valueExpr) v $base AND og.status = 'checkout_completed' GROUP BY DATE(o.$ordersCreatedCol) ORDER BY d";
  }
  $tries[] = "SELECT DATE(o.$ordersCreatedCol) d, SUM($valueExpr) v $base
                AND EXISTS(SELECT 1 FROM payments pay WHERE pay.order_group_id = og.id AND pay.status IN ('paid','confirmed','authorized'))
                GROUP BY DATE(o.$ordersCreatedCol) ORDER BY d";
  $tries[] = "SELECT DATE(o.$ordersCreatedCol) d, SUM($valueExpr) v $base GROUP BY DATE(o.$ordersCreatedCol) ORDER BY d";

  foreach ($tries as $sql) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute(['owner_id' => $ownerId, 'from' => $from, 'to' => $to]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['d']] = (float)$r['v'];
      }
      return $out;
    } catch (Throwable $e) {
      // kolejny fallback
    }
  }
  return [];
})();

// ───────────────────────────── Serie zamówień (COUNT by day) ─────────────────────────────
$ordersDaily = [];
try {
  $st = $pdo->prepare("
        SELECT DATE($ordersCreatedCol) d, COUNT(*) v
        FROM orders
        WHERE owner_id = :owner_id AND $ordersCreatedCol >= :from AND $ordersCreatedCol < :to
        GROUP BY DATE($ordersCreatedCol) ORDER BY d
    ");
  $st->execute(['owner_id' => $ownerId, 'from' => $from, 'to' => $to]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ordersDaily[$r['d']] = (int)$r['v'];
  }
} catch (Throwable $e) {
  // nic – wykres będzie pusty
}

// ───────────────────────────── Heatmapa 24x7 (COUNT orders) ─────────────────────────────
$heat = [];
for ($d = 0; $d < 7; $d++) $heat[$d] = array_fill(0, 24, 0);

try {
  $st = $pdo->prepare("
        SELECT WEEKDAY($ordersCreatedCol) dow, HOUR($ordersCreatedCol) h, COUNT(*) c
        FROM orders
        WHERE owner_id = :owner_id AND $ordersCreatedCol >= :from AND $ordersCreatedCol < :to
        GROUP BY WEEKDAY($ordersCreatedCol), HOUR($ordersCreatedCol)
    ");
  $st->execute(['owner_id' => $ownerId, 'from' => $from, 'to' => $to]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $heat[(int)$r['dow']][(int)$r['h']] = (int)$r['c'];
  }
} catch (Throwable $e) { /* pusto */
}

// ───────────────────────────── Breakdown płatności (status) ─────────────────────────────
$payments = [];
try {
  $st = $pdo->prepare("
        SELECT pay.$paymentsStatusCol AS status, COUNT(*) cnt
        FROM payments pay
        JOIN order_groups og ON og.id = pay.order_group_id
        JOIN orders o ON o.id = og.order_id
        WHERE o.owner_id = :owner_id AND o.$ordersCreatedCol >= :from AND o.$ordersCreatedCol < :to
        GROUP BY pay.$paymentsStatusCol
        ORDER BY cnt DESC
    ");
  $st->execute(['owner_id' => $ownerId, 'from' => $from, 'to' => $to]);
  $payments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $payments = [];
}

// ───────────────────────────────────── Output ─────────────────────────────────────
$out = [
  'ok'               => true,
  'labels'           => $labels,
  'salesDaily'       => $salesDaily,
  'ordersDaily'      => $ordersDaily,
  'orders_today'     => $orders_today,
  'orders_yesterday' => $orders_yesterday,
  'sales_today'      => $sales_today,
  'sales_yesterday'  => $sales_yesterday,
  'heatmap'          => $heat,
  'payments'         => $payments,
];

if ($DEBUG) {
  $out['_debug'] = [
    'owner_id'          => $ownerId,
    'window'            => ['from' => $from, 'to' => $to],
    'ordersCreatedCol'  => $ordersCreatedCol,
    'orderGroupsDone'   => $orderGroupsDone,
    'orderGroupsStatus' => $orderGroupsStatus,
    'itemsQtyCol'       => $itemsQtyCol,
    'itemsPriceCol'     => $itemsPriceCol,
    'paymentsStatusCol' => $paymentsStatusCol,
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
