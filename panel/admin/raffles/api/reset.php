<?php
// ===== [admin/raffles/api/reset.php] — zamknij losowanie =====
?>
<?php /* file: admin/raffles/api/reset.php */ ?>
<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');
$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    http_response_code(422);
    echo json_encode(['error' => 'Brak ID']);
    exit;
}
$pdo->prepare("UPDATE draws SET status='closed', updated_at=NOW() WHERE id=:id")->execute(['id' => $id]);
echo json_encode(['success' => true]);
?>