<?php
use CentralMessaging\Cw;

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/engine/centralMessaging/Cw.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$channel  = $_GET['channel'] ?? ''; // opcjonalny filtr

$sql = "SELECT id FROM messages WHERE owner_id=? AND status='queued'";
$params = [$owner_id];
if ($channel !== '') { $sql .= " AND channel=?"; $params[] = $channel; }
$sql .= " ORDER BY id ASC LIMIT 200";

$st = $pdo->prepare($sql); $st->execute($params);
$ids = $st->fetchAll(PDO::FETCH_COLUMN);

$ok=0;$fail=0;
foreach ($ids as $id) { Cw::trySend($pdo,(int)$id) ? $ok++ : $fail++; }

header('Content-Type: application/json');
echo json_encode(['processed'=>count($ids),'ok'=>$ok,'fail'=>$fail]);
