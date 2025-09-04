<?php
// api/facebook/disconnect.php
// 1. opis czynności lub funkcji
// Usuwa tokeny FB powiązane z ownerem. (Nie odwołuje uprawnień po stronie FB – to można dodać osobno.)

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../includes/settings.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
    http_response_code(403);
    echo 'Brak ownera';
    exit;
}

$del = $pdo->prepare("DELETE FROM facebook_tokens WHERE owner_id = :oid");
$del->execute([':oid' => $owner_id]);

// (opcjonalnie) usuń ustawienia wygodne
set_setting($owner_id, 'facebook_page_id', '');
set_setting($owner_id, 'facebook_token', ''); // legacy

logg('info', 'facebook.disconnect', '🔌 FB rozłączono', ['owner_id' => $owner_id]);

$_SESSION['success_message'] = "🔌 Rozłączono Facebooka.";
header('Location: /admin/settings/integracje/');
exit;
