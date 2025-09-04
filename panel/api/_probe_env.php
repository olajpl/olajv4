<?php
// /api/_probe_env.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

function table_exists(PDO $pdo, string $t): bool
{
    try {
        $pdo->query("DESCRIBE `$t`");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
$out = ['ok' => true, 'php' => phpversion(), 'db' => []];

$tables = ['messages', 'logs', 'audit_logs', 'fb_webhook_events', 'products', 'orders', 'order_groups', 'order_items', 'clients'];
foreach ($tables as $t) {
    $out['db'][$t] = table_exists($pdo, $t);
}

// spróbuj insert do messages (transakcyjnie)
try {
    if ($out['db']['messages']) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO messages
      (owner_id, client_id, operator_user_id, order_id, order_group_id, live_id,
       direction, channel, platform, platform_user_id, platform_msg_id, status, content, error, flags, metadata, created_at, sent_at)
      VALUES (1, NULL, NULL, NULL, NULL, NULL, 'in','messenger','facebook','PROBE_PSID','PROBE_MID','pending','probe message', NULL, '', NULL, NOW(), NULL)");
        $st->execute();
        $id = (int)$pdo->lastInsertId();
        $pdo->rollBack(); // nie zostawiajmy śmieci
        $out['probe_insert_messages'] = ['ok' => true, 'last_id' => $id];
    } else {
        $out['probe_insert_messages'] = ['ok' => false, 'reason' => 'no_table'];
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $_) {
        }
    }
    $out['probe_insert_messages'] = ['ok' => false, 'error' => $e->getMessage()];
}

// policz ile jest w messages/logs (jeśli istnieją)
try {
    if ($out['db']['messages']) {
        $out['counts']['messages'] = (int)$pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    }
    if ($out['db']['logs']) {
        $out['counts']['logs'] = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
    } elseif ($out['db']['audit_logs']) {
        $out['counts']['audit_logs'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    }
} catch (Throwable $e) {
    $out['counts_error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
