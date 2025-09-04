<?php
// admin/live/ajax/ajax_live_temp_list.php
require_once __DIR__ . '/__live_boot.php';

$live_id = (int)($_GET['live_id'] ?? 0);
$owner_id= (int)($_GET['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
$q       = trim((string)($_GET['q'] ?? ''));
$fltStatus = trim((string)($_GET['status'] ?? ''));
$fltSource = trim((string)($_GET['source'] ?? ''));
$fltRes    = trim((string)($_GET['res'] ?? ''));

if ($live_id<=0) { echo '<div class="text-gray-500 p-3">Brak LIVE.</div>'; exit; }

// Pobranie pozycji
$params = [':lid'=>$live_id];
$sql = "
  SELECT lt.id, lt.client_id, lt.name, lt.sku, lt.qty, lt.price, lt.vat_rate,
         lt.source_type, lt.product_id, lt.reservation_id,
         c.name AS client_name, c.email AS client_email
  FROM live_temp lt
  LEFT JOIN clients c ON c.id = lt.client_id
  WHERE lt.live_id = :lid
";
if ($owner_id>0) { $sql .= " AND lt.owner_id = :oid"; $params[':oid'] = $owner_id; }
if ($q!=='') { $sql .= " AND (c.name LIKE :q OR lt.name LIKE :q)"; $params[':q']="%{$q}%"; }
if ($fltSource!=='') { $sql .= " AND lt.source_type = :src"; $params[':src']=$fltSource; }
// status/res filtr możesz podłączyć po dodaniu kolumn/łącz z orders/stock_reservations

$sql .= " ORDER BY c.name ASC, lt.id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Grupowanie po kliencie
$byClient = [];
foreach ($rows as $r) {
  $cid = (int)$r['client_id'];
  $byClient[$cid]['client_name'] = $r['client_name'] ?: ('Klient #'.$cid);
  $byClient[$cid]['client_email']= $r['client_email'] ?: '';
  $byClient[$cid]['items'][] = $r;
}

if (!$byClient) {
  echo '<div class="text-gray-500 p-3">Brak pozycji.</div>';
  exit;
}

// Render
foreach ($byClient as $cid => $group) {
  $title = htmlspecialchars($group['client_name']) . ($group['client_email'] ? ' <'.htmlspecialchars($group['client_email']).'>' : '');
  echo '<div class="border rounded-xl p-3">';
  echo '<div class="font-medium mb-2">👤 '.$title.'</div>';
  echo '<div class="space-y-2">';
  foreach ($group['items'] as $it) {
    $label = htmlspecialchars($it['name'] ?: ('Produkt #'.$it['product_id']));
    $sku   = htmlspecialchars($it['sku'] ?? '');
    $qty   = (int)$it['qty'];
    $src   = $it['source_type']==='catalog' ? 'katalog' : 'custom';
    $rowId = (int)$it['id'];
    echo '<div class="flex items-center justify-between bg-gray-50 rounded p-2">';
    echo '  <div class="text-sm">'.$label.($sku? ' <span class="text-gray-500">('.$sku.')</span>' : '').' • <span class="text-gray-600">'.$src.'</span></div>';
    echo '  <div class="flex items-center gap-2">';
    echo '    <div class="text-sm">qty: '.$qty.'</div>';
    echo '    <button class="btn btn-soft" data-del-id="'.$rowId.'">🗑</button>';
    echo '  </div>';
    echo '</div>';
  }
  echo '</div>';
  echo '</div>';
}
