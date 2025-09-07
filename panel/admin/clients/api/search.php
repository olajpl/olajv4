<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$qRaw    = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$limit   = max(1, min(30, (int)($_GET['limit'] ?? 20)));

if ($ownerId <= 0) { http_response_code(403); echo json_encode(['items'=>[], 'error'=>'NO_OWNER']); exit; }

$q   = $qRaw;
$qd  = preg_replace('/\D+/', '', $qRaw);

$where  = ["c.owner_id = :oid", "c.deleted_at IS NULL"];
$params = [':oid' => $ownerId];

if ($q !== '' && $q !== '*') {
    $parts = [];
    $parts[] = "c.`name`  LIKE :q1";
    $parts[] = "c.`email` LIKE :q2";
    $params[':q1'] = '%'.$q.'%';
    $params[':q2'] = '%'.$q.'%';

    if (ctype_digit($q)) {
        $parts[] = "c.id = :qid";
        $params[':qid'] = (int)$q;
    }

    if ($qd !== '') {
        $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.`phone`,' ',''),'-',''),'+',''),'(',''),')','') LIKE :qd";
        $params[':qd'] = '%'.$qd.'%';
    }

    $where[] = '(' . implode(' OR ', $parts) . ')';
}

$sql =
    "SELECT c.id, c.`name` AS name, c.`email` AS email, c.`phone` AS phone
     FROM clients c
     WHERE " . implode(' AND ', $where) . "
     ORDER BY c.`updated_at` DESC, c.id DESC
     LIMIT " . (int)$limit;

try {
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $r) {
        $name  = trim((string)($r['name']  ?? ''));
        $email = trim((string)($r['email'] ?? ''));
        $phone = trim((string)($r['phone'] ?? ''));
        $label = $name !== '' ? $name : ($email !== '' ? $email : ($phone !== '' ? $phone : ('#'.$r['id'])));
        $bits  = [];
        if ($email) $bits[] = $email;
        if ($phone) $bits[] = $phone;
        if ($bits) $label .= ' ('.implode(', ', $bits).')';
        $items[] = ['id'=>(int)$r['id'], 'text'=>$label];
    }

    echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode([
        'items'=>[],
        'error'=>'EXCEPTION',
        'message'=>$e->getMessage(),
    ]);
}
