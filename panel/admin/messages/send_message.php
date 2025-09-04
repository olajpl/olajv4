<?php
// admin/messages/send_message.php — wysyłka z panelu (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ───────────────────────────────────────────────────────────────
// Wejście + guardy
// ───────────────────────────────────────────────────────────────
$owner_id    = (int)($_SESSION['user']['owner_id'] ?? 0);
$operator_id = (int)($_SESSION['user']['id'] ?? 0);

$client_id         = (int)($_POST['client_id'] ?? 0);
$platform          = trim((string)($_POST['platform'] ?? 'chat'));
$platform_user_id  = trim((string)($_POST['platform_user_id'] ?? '')); // ← zgodnie z DB
$channel           = trim((string)($_POST['channel'] ?? 'manual'));    // DB default: 'manual'
$direction         = trim((string)($_POST['direction'] ?? 'out'));     // 'out' z panelu
$text_message      = trim((string)($_POST['message'] ?? ''));

// (opcjonalnie) CSRF — jeśli masz token w formularzu, odkomentuj:
// $csrf = (string)($_POST['csrf'] ?? '');
// if ($csrf === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
//     http_response_code(403);
//     exit('CSRF validation failed');
// }

if ($owner_id <= 0 || $client_id <= 0) {
    http_response_code(400);
    exit('❌ Brak owner_id albo client_id.');
}

// Tenancy guard — klient musi należeć do ownera
$st = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND owner_id = ? LIMIT 1');
$st->execute([$client_id, $owner_id]);
if (!$st->fetchColumn()) {
    http_response_code(403);
    exit('❌ Klient nie należy do tego właściciela.');
}

// Whitelist platform/channel/direction
$PLAT_OK  = ['facebook', 'instagram', 'chat', 'mobile', 'email', 'sms', 'push', 'manual'];
$CHAN_OK  = ['messenger', 'email', 'sms', 'push', 'manual'];  // zgodnie ze schematem
$DIR_OK   = ['in', 'out'];

if (!in_array($platform, $PLAT_OK, true)) $platform = 'chat';
if (!in_array($channel,  $CHAN_OK, true)) $channel  = 'manual';
if (!in_array($direction, $DIR_OK,  true)) $direction = 'out';

// ───────────────────────────────────────────────────────────────
// Upload obrazka → [img]URL
// ───────────────────────────────────────────────────────────────
$uploadedImgUrl = null;
if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $ym  = date('Y/m');
        $dir = __DIR__ . '/../../uploads/chat/' . $ym;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path  = $dir . '/' . $fname;
        if (@move_uploaded_file($_FILES['image']['tmp_name'], $path)) {
            $uploadedImgUrl = '/uploads/chat/' . $ym . '/' . $fname; // publiczny URL
        }
    }
}

// Zbuduj payload(y) do wstawienia (obrazek + tekst = 2 rekordy)
$toInsert = [];
if ($uploadedImgUrl) $toInsert[] = '[img]' . $uploadedImgUrl;
if ($text_message !== '') $toInsert[] = $text_message;

if (!$toInsert) {
    http_response_code(400);
    exit('❌ Brak danych (ani obrazka, ani tekstu).');
}

// ───────────────────────────────────────────────────────────────
// INSERT do messages (zgodne ze schematem V4)
// ───────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $sql = "
      INSERT INTO messages
        (owner_id, client_id, operator_user_id,
         direction, channel, sender_type,
         platform, platform_user_id,
         status, retries, content, created_at)
      VALUES
        (:owner_id, :client_id, :operator_user_id,
         :direction, :channel, 'operator',
         :platform, :platform_user_id,
         'new', 0, :content, NOW())
    ";
    $ins = $pdo->prepare($sql);

    foreach ($toInsert as $content) {
        $ins->execute([
            'owner_id'         => $owner_id,
            'client_id'        => $client_id,
            'operator_user_id' => $operator_id ?: null,
            'direction'        => $direction ?: 'out',
            'channel'          => $channel   ?: 'manual',
            'platform'         => $platform  ?: 'chat',
            'platform_user_id' => $platform_user_id ?: null,
            'content'          => $content,
        ]);
    }

    $pdo->commit();

    // Log dla wglądu (centralny)
    if (function_exists('logg')) {
        logg('info', 'messages.panel.send', 'Panel message(s) inserted', [
            'owner_id'  => $owner_id,
            'client_id' => $client_id,
            'count'     => count($toInsert),
            'platform'  => $platform,
            'channel'   => $channel,
        ]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (function_exists('logg')) {
        logg('error', 'messages.panel.send', 'Insert failed', [
            'owner_id'  => $owner_id,
            'client_id' => $client_id,
            'err'       => $e->getMessage(),
        ]);
    }
    http_response_code(500);
    exit('❌ Błąd zapisu: ' . htmlspecialchars($e->getMessage()));
}

// ───────────────────────────────────────────────────────────────
// Best-effort wysyłka do zewnętrznej platformy (np. FB Messenger)
// (jeśli masz helper `sendMessengerMessage($page_id, $psid, $text)`)
// ───────────────────────────────────────────────────────────────
if ($platform === 'facebook' && $platform_user_id !== '') {
    try {
        // Przyklad: pobranie page_id dla ownera (dostosuj do swojej tabeli)
        $st = $pdo->prepare("SELECT page_id FROM facebook_tokens WHERE owner_id = ? LIMIT 1");
        $st->execute([$owner_id]);
        $page_id = (string)($st->fetchColumn() ?: '');

        if ($page_id !== '' && function_exists('sendMessengerMessage')) {
            foreach ($toInsert as $msg) {
                $out = ($msg && str_starts_with($msg, '[img]')) ? trim(substr($msg, 5)) : $msg;
                // Wysyłamy treść; jeżeli masz oddzielny upload do FB, tu go użyj.
                @sendMessengerMessage($page_id, $platform_user_id, $out);
            }
            if (function_exists('logg')) {
                logg('info', 'messages.panel.fb_send', 'Sent to FB', [
                    'owner_id'         => $owner_id,
                    'client_id'        => $client_id,
                    'platform_user_id' => $platform_user_id,
                    'count'            => count($toInsert),
                ]);
            }
        }
    } catch (Throwable $e) {
        if (function_exists('logg')) {
            logg('warning', 'messages.panel.fb_send', 'FB send failed (best-effort)', [
                'owner_id'  => $owner_id,
                'client_id' => $client_id,
                'err'       => $e->getMessage(),
            ]);
        }
        // cicho: panel i tak ma już zapisane wiadomości
    }
}

// ───────────────────────────────────────────────────────────────
// Powrót do widoku rozmowy (z toastem)
// ───────────────────────────────────────────────────────────────
header('Location: /admin/messages/view.php?client_id=' . (int)$client_id . '&msg=sent');
exit;
