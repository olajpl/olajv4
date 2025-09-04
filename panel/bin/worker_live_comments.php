<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/parser.php';

$st = $pdo->prepare("
  SELECT lc.id, lc.owner_id, lc.client_id, lc.message
  FROM live_comments lc
  WHERE lc.processed = 0
  ORDER BY lc.id ASC
  LIMIT 200
");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $ownerId = (int)$r['owner_id'];
    $clientId = (int)($r['client_id'] ?? 0);
    $msg = (string)$r['message'];

    try {
        // Użyj parsera „po naszemu”
        $res = parse_message($ownerId, 'facebook', 'live_comment:'.$clientId, $msg);

        if (!empty($res['items']) && is_array($res['items'])) {
            $ins = $pdo->prepare("
              INSERT INTO live_comment_items (comment_id, product_id, custom_name, quantity, variant, confidence)
              VALUES (:cid,:pid,:name,:qty,:variant,:conf)
            ");
            foreach ($res['items'] as $it) {
                $ins->execute([
                    ':cid'     => $r['id'],
                    ':pid'     => $it['product_id'] ?? null,
                    ':name'    => $it['custom_name'] ?? null,
                    ':qty'     => (int)($it['qty'] ?? 1),
                    ':variant' => $it['variant'] ?? null,
                    ':conf'    => $it['confidence'] ?? null,
                ]);
            }
        } elseif (!empty($res['product_id'])) {
            // stary kontrakt parsera
            $pdo->prepare("INSERT INTO live_comment_items (comment_id, product_id, quantity) VALUES (?,?,?)")
                ->execute([$r['id'], (int)$res['product_id'], (int)($res['qty'] ?? 1)]);
        }

        // oznacz komentarz jako przetworzony
        $pdo->prepare("UPDATE live_comments SET processed=1 WHERE id=?")->execute([$r['id']]);

        wlog('live_comment processed', 'info', ['id'=>$r['id'], 'owner_id'=>$ownerId]);
    } catch (\Throwable $e) {
        wlog('live_comment process error: '.$e->getMessage(), 'error', [
            'id'=>$r['id'], 'trace'=>$e->getTraceAsString()
        ]);
    }
}
