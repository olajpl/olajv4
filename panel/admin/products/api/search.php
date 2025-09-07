<?php
// admin/products/api/search.php — Olaj V4: ProductEngine-first + SQL fallback (owner-scope)
declare(strict_types=1);

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$qRaw    = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$limit   = max(1, min(20, (int)($_GET['limit'] ?? 20)));

if ($ownerId <= 0) { http_response_code(403); echo json_encode(['items'=>[], 'error'=>'NO_OWNER']); exit; }

$q = $qRaw;
$qp = '%'.$q.'%';

// 0) Spróbuj ProductEngine (jeśli istnieje)
try {
    if (class_exists('\\Engine\\Orders\\ProductEngine')) {
        $pe = new \Engine\Orders\ProductEngine($pdo);
        foreach (['search','searchProducts','findByQuery'] as $m) {
            if (method_exists($pe, $m)) {
                $rows = $pe->$m($ownerId, $q, $limit);
                if (is_array($rows) && $rows) {
                    echo json_encode(['items'=>array_map(function($r){
                        $id   = (int)($r['id'] ?? 0);
                        $name = (string)($r['name'] ?? '');
                        $sku  = (string)($r['sku'] ?? ($r['code'] ?? ''));
                        $price= (float)($r['unit_price'] ?? 0);
                        $vat  = (float)($r['vat_rate'] ?? 23);
                        $txt  = trim($name) !== '' ? $name : ($sku !== '' ? $sku : ('#'.$id));
                        if ($sku !== '') $txt .= " [{$sku}]";
                        $txt .= ' — ' . number_format($price, 2, ',', ' ') . ' zł';
                        return [
                            'id' => $id,
                            'text' => $txt,
                            'name' => $name,
                            'sku'  => $sku,
                            'unit_price' => $price,
                            'vat_rate'   => $vat,
                        ];
                    }, $rows)], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }
        }
    }
} catch (\Throwable $e) {
    logg('warning','products.search','ProductEngine_failed',['err'=>$e->getMessage()]);
}

// 1) SQL fallback (kolumny auto)
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
$want = ['id','owner_id','name','code','sku','ean','twelve_nc','unit_price','vat_rate','deleted_at','updated_at'];
$in   = str_repeat('?,', count($want)-1) . '?';
$st   = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name='products' AND column_name IN ($in)");
$st->execute(array_merge([$db], $want));
$cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

$nameCol = in_array('name',$cols,true) ? 'name' : null;
$codeCol = in_array('code',$cols,true) ? 'code' : null;
$skuCol  = in_array('sku',$cols,true)  ? 'sku'  : null;
$eanCol  = in_array('ean',$cols,true)  ? 'ean'  : null;
$tncCol  = in_array('twelve_nc',$cols,true) ? 'twelve_nc' : null;
$priceCol= in_array('unit_price',$cols,true) ? 'unit_price' : null;
$vatCol  = in_array('vat_rate',$cols,true) ? 'vat_rate' : null;
$updCol  = in_array('updated_at',$cols,true) ? 'updated_at' : 'id';

$select = "p.id";
if ($nameCol)  $select .= ", p.`$nameCol` AS name";
if ($skuCol)   $select .= ", p.`$skuCol` AS sku";
elseif ($codeCol) $select .= ", p.`$codeCol` AS sku";
if ($priceCol) $select .= ", p.`$priceCol` AS unit_price";
if ($vatCol)   $select .= ", p.`$vatCol`   AS vat_rate";

$where  = ["p.owner_id = :oid"];
$params = [':oid'=>$ownerId];

if (in_array('deleted_at',$cols,true)) $where[] = "(p.`deleted_at` IS NULL)";

if ($q !== '' && $q !== '*') {
    // osobne placeholdery (bez HY093)
    $parts = [];
    $i = 1;
    foreach ([$codeCol,$skuCol,$eanCol,$tncCol,$nameCol] as $c) {
        if ($c) { $ph=":q{$i}"; $parts[] = "p.`$c` LIKE {$ph}"; $params[$ph]=$qp; $i++; }
    }
    if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
}

$sql = "SELECT $select FROM products p WHERE " . implode(' AND ', $where) .
       " ORDER BY p.`$updCol` DESC, p.id DESC LIMIT " . (int)$limit;

try {
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) {
        $st->bindValue($k, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $r) {
        $id    = (int)$r['id'];
        $name  = (string)($r['name'] ?? '');
        $sku   = (string)($r['sku']  ?? '');
        $price = (float)($r['unit_price'] ?? 0);
        $vat   = (float)($r['vat_rate']   ?? 23);
        $txt   = $name !== '' ? $name : ($sku !== '' ? $sku : ('#'.$id));
        if ($sku !== '') $txt .= " [{$sku}]";
        $txt  .= ' — ' . number_format($price, 2, ',', ' ') . ' zł';
        $items[] = [
            'id'=>$id,
            'text'=>$txt,
            'name'=>$name,
            'sku'=>$sku,
            'unit_price'=>$price,
            'vat_rate'=>$vat,
        ];
    }

    echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    logg('error','products.search','SQL_error',['err'=>$e->getMessage(),'sql'=>$sql,'params'=>$params]);
    http_response_code(500);
    echo json_encode(['items'=>[], 'error'=>'EXCEPTION', 'message'=>$e->getMessage()]);
}
