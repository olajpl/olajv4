<?php
// /api/_probe_insert_message.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

$out = ['ok' => false];
try {
    $sql = "INSERT INTO messages
    (owner_id, client_id, operator_user_id, order_id, order_group_id, live_id,
     direction, channel, platform, platform_user_id, platform_msg_id, status, content, error, flags, metadata, created_at, sent_at)
    VALUES (1, NULL, NULL, NULL, NULL, NULL,
     'in','messenger','facebook','UNIT_TEST_PSID','UNIT_TEST_MID','pending','unit test message', NULL, '', NULL, NOW(), NULL)";
    $st = $pdo->prepare($sql);
    $st->execute();
    $out['ok'] = true;
    $out['id'] = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
