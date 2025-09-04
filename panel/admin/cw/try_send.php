<?php
// admin/cw/try_send.php – ręczna próba wysyłki CW z pełnym logowaniem
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/engine/centralMessaging/Cw.php';
require_once dirname(__DIR__, 2) . '/engine/Log/LogEngine.php';

use CentralMessaging\Cw;
use Engine\Log\LogEngine;

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId = (int)($_SESSION['user']['id'] ?? 0);

if ($id <= 0 || $ownerId <= 0) {
    echo json_encode(['ok' => false, 'why' => 'invalid_input']);
    exit;
}

// 1) Pobierz wiadomość
$st = $pdo->prepare("SELECT * FROM messages WHERE id = ? AND owner_id = ? LIMIT 1");
$st->execute([$id, $ownerId]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'why' => 'not_found']);
    exit;
}

// 2) Wstępne sanity checks
if ($row['status'] !== 'queued') {
    echo json_encode(['ok' => false, 'why' => 'not_queued', 'status' => $row['status']]);
    exit;
}
if ($row['direction'] !== 'out') {
    echo json_encode(['ok' => false, 'why' => 'not_outgoing', 'direction' => $row['direction']]);
    exit;
}
if (empty($row['channel'])) {
    echo json_encode(['ok' => false, 'why' => 'no_channel']);
    exit;
}

// 3) Pre-walidacja kanału
$why = null;
switch ($row['channel']) {
    case 'messenger':
        $thread = $row['platform_thread_id'];
        if (!$thread && $row['client_id']) {
            $q = $pdo->prepare("SELECT platform_thread_id FROM messages
                WHERE client_id = ? AND platform = 'facebook' AND channel = 'messenger'
                AND platform_thread_id IS NOT NULL AND direction = 'in'
                ORDER BY id DESC LIMIT 1");
            $q->execute([(int)$row['client_id']]);
            $thread = $q->fetchColumn() ?: null;
        }
        if (!$thread) $why = 'missing_thread_id';
        break;

    case 'email':
        if (empty($row['client_id'])) {
            $why = 'missing_client_for_email';
            break;
        }
        $q = $pdo->prepare("SELECT email FROM clients WHERE id = ? LIMIT 1");
        $q->execute([(int)$row['client_id']]);
        $email = $q->fetchColumn();
        if (!$email) $why = 'missing_email';
        break;

    case 'sms':
        if (empty($row['client_id'])) {
            $why = 'missing_client_for_sms';
            break;
        }
        $q = $pdo->prepare("SELECT phone FROM clients WHERE id = ? LIMIT 1");
        $q->execute([(int)$row['client_id']]);
        $phone = $q->fetchColumn();
        if (!$phone) $why = 'missing_phone';
        break;
}

if ($why) {
    echo json_encode(['ok' => false, 'why' => $why, 'row' => $row]);
    exit;
}

// 4) Próba wysyłki
try {
    $log = LogEngine::create($pdo, $ownerId);
    $log->info('cw.try_send', 'Manual attempt to send message', ['message_id' => $id, 'user_id' => $userId]);

    $ok = Cw::trySend($pdo, $id);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'why' => 'exception', 'error' => $e->getMessage()]);
    exit;
}

// 5) Odczyt statusu po wysyłce
$st = $pdo->prepare("SELECT status, retries, error_message, platform_msg_id, sent_at FROM messages WHERE id = ?");
$st->execute([$id]);
$after = $st->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'ok'         => (bool)$ok,
    'status'     => $after['status'] ?? null,
    'retries'    => (int)($after['retries'] ?? 0),
    'provider_id'=> $after['platform_msg_id'] ?? null,
    'error'      => $after['error_message'] ?? null,
    'sent_at'    => $after['sent_at'] ?? null,
    'row'        => $row,
    'after'      => $after
]);
