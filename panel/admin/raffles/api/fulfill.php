<?php
// ===== [admin/raffles/api/fulfill.php] — oznacz wydanie nagrody =====
?>
<?php /* file: admin/raffles/api/fulfill.php */ ?>
<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json');
$res_id = (int)($_POST['result_id'] ?? 0);
$notes  = trim($_POST['notes'] ?? '');
if (!$res_id) {
    http_response_code(422);
    echo json_encode(['error' => 'Brak result_id']);
    exit;
}
$pdo->prepare("UPDATE draw_claims SET claim_status='fulfilled', fulfilled_at=NOW(), notes=:n, updated_at=NOW() WHERE result_id=:rid")
    ->execute(['n' => $notes, 'rid' => $res_id]);
echo json_encode(['success' => true]);
?>
