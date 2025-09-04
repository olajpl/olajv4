<?php
// admin/settings/Enums/delete.php — Olaj.pl V4 (usuwanie enum_value)
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

if (!($_SESSION['user']['is_suadmin'] ?? false)) {
    http_response_code(403);
    exit('Brak dostępu');
}

$id = (int)($_GET['id'] ?? 0);
$setKey = $_GET['set'] ?? '';

if ($id > 0 && $setKey) {
    $stmt = $pdo->prepare("DELETE FROM enum_values WHERE id = ? AND set_key = ? LIMIT 1");
    $stmt->execute([$id, $setKey]);
}

header("Location: index.php?set=" . urlencode($setKey));
exit;
