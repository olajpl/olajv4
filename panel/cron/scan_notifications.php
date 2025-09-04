<?php
// cron/scan_notifications.php — Olaj V4 worker do wysyłki przypomnień (braki / nieopłacone)
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../engine/Notifications/NotificationEngine.php';
require_once __DIR__ . '/../engine/CentralMessaging/Cw.php';
require_once __DIR__ . '/../engine/Checkout/CheckoutResolver.php';

use Engine\Notifications\NotificationEngine;
use Engine\CentralMessaging\Cw;
use Engine\Checkout\CheckoutResolver;

// Init
$pdo = $pdo ?? null;
if (!$pdo) {
    echo "DB not ready\n";
    exit(1);
}

$cw = new Cw($pdo);
$noti = new NotificationEngine($pdo, $cw, new class {
    public function info($c,$m,$ctx=[]){ logg('info',$c,$m,$ctx); }
    public function error($c,$m,$ctx=[]){ logg('error',$c,$m,$ctx); }
});
$resolver = new CheckoutResolver();

// Helper: zbuduj checkout URL
function checkoutUrl(PDO $pdo, int $groupId): string {
    $resolver = new CheckoutResolver();
    $group = $resolver->findGroupByToken($pdo, (string)$groupId);
    $token = $group['checkout_token'] ?? $group['group_token'] ?? null;
    $base = $_ENV['SHOP_BASE_URL'] ?? 'https://olaj.pl';
    return $token ? $base . '/checkout/summary.php?token=' . urlencode($token) : $base . '/moje.php';
}

// 🔶 BRKI DANYCH
$sqlMissing = <<<SQL
SELECT o.owner_id, o.id AS order_id, o.client_id, og.id AS group_id
FROM orders o
JOIN order_groups og ON og.order_id = o.id
LEFT JOIN shipping_addresses sa ON sa.order_group_id = og.id
WHERE o.order_status IN ('otwarta_paczka:add_products','otwarta_paczka:payment_only','oczekuje_na_płatność')
  AND (sa.id IS NULL
    OR sa.city IS NULL OR sa.city = ''
    OR sa.street IS NULL OR sa.street = ''
    OR sa.zip IS NULL OR sa.zip = ''
    OR (sa.is_locker = 1 AND (sa.locker_id IS NULL OR sa.locker_id = '')))
  AND og.checkout_completed = 0
  AND o.deleted_at IS NULL
LIMIT 500
SQL;

foreach ($pdo->query($sqlMissing) ?: [] as $row) {
    $ctx = [
        'order_id' => (int)$row['order_id'],
        'client_id'=> (int)$row['client_id'],
        'group_id' => (int)$row['group_id'],
        'checkout_url' => checkoutUrl($pdo, (int)$row['group_id']),
        'missing_fields' => ['city','street','zip'], // TODO: real check
    ];
    $noti->dispatch([
        'owner_id' => (int)$row['owner_id'],
        'event_key' => 'order_missing_data',
        'context'  => $ctx,
    ]);
}

// 🔴 NIEOPŁACONE
$sqlUnpaid = <<<SQL
SELECT o.owner_id, o.id AS order_id, o.client_id, og.id AS group_id,
       TIMESTAMPDIFF(HOUR, og.created_at, NOW()) AS age_h
FROM orders o
JOIN order_groups og ON og.order_id = o.id
LEFT JOIN payments p ON p.order_group_id = og.id
WHERE og.paid_status IN ('nieopłacona','częściowa')
  AND o.order_status IN ('oczekuje_na_płatność','otwarta_paczka:payment_only','otwarta_paczka:add_products')
  AND (SELECT MAX(pp.status) FROM payments pp WHERE pp.order_group_id=og.id) != 'paid'
  AND TIMESTAMPDIFF(HOUR, og.created_at, NOW()) >= 6
  AND o.deleted_at IS NULL
  AND og.checkout_completed = 0
GROUP BY og.id
LIMIT 500
SQL;

foreach ($pdo->query($sqlUnpaid) ?: [] as $row) {
    $ctx = [
        'order_id' => (int)$row['order_id'],
        'client_id'=> (int)$row['client_id'],
        'group_id' => (int)$row['group_id'],
        'checkout_url' => checkoutUrl($pdo, (int)$row['group_id']),
        'age_h' => (int)$row['age_h'],
    ];
    $noti->dispatch([
        'owner_id' => (int)$row['owner_id'],
        'event_key' => 'order_payment_overdue',
        'context'  => $ctx,
    ]);
}

logg('info', 'cron.scan_notifications', '✅ Done.');
echo "Scan complete\n";
