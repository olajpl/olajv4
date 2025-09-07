<?php
// admin/products/save_bulk_add.php — V6 engines: ProductEngine (dane) + StockEngine (stan)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: text/html; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: bulk_add.php?msg=error&reason=not_post'); exit; }

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) { header('Location: bulk_add.php?msg=error&reason=no_owner'); exit; }

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  header('Location: bulk_add.php?msg=error&reason=csrf_mismatch'); exit;
}

/* ——— Engines ——— */
$PE = null; // ProductEngine (opcjonalnie)
try {
  $p1 = __DIR__ . '/../../engine/orders/ProductEngine.php';
  $p2 = __DIR__ . '/../../engine/Orders/ProductEngine.php';
  if (is_file($p1)) require_once $p1;
  if (is_file($p2)) require_once $p2;
  if (class_exists('Engine\\Orders\\ProductEngine')) {
    $PE = new \Engine\Orders\ProductEngine($pdo);
  }
} catch (Throwable $e) {
  if (function_exists('logg')) logg('warning','products.bulk_add','ProductEngine init failed',['err'=>$e->getMessage()]);
}

$SE = null; // StockEngine (preferowany do stanu)
try {
  $s1 = __DIR__ . '/../../engine/stock/StockEngine.php';
  $s2 = __DIR__ . '/../../engine/Stock/StockEngine.php';
  if (is_file($s1)) require_once $s1;
  if (is_file($s2)) require_once $s2;
  if (class_exists('Engine\\Stock\\StockEngine')) {
    $SE = new \Engine\Stock\StockEngine($pdo);
  }
} catch (Throwable $e) {
  if (function_exists('logg')) logg('warning','products.bulk_add','StockEngine init failed',['err'=>$e->getMessage()]);
}

/* ——— Kolumny (aliasy) ——— */
$cols=[]; try{ $rs=$pdo->query("SHOW COLUMNS FROM products"); foreach(($rs->fetchAll(PDO::FETCH_ASSOC)?:[]) as $c){ $cols[strtolower($c['Field'])]=true; } }catch(Throwable $e){}
$has = fn(string $n): bool => isset($cols[strtolower($n)]);

$codeField      = $has('code') ? 'code' : ($has('sku') ? 'sku' : null);
$priceField     = $has('price') ? 'price' : ($has('unit_price') ? 'unit_price' : null);
$vatField       = $has('vat_rate') ? 'vat_rate' : ($has('vat') ? 'vat' : null);
$twelveField    = $has('twelve_nc') ? 'twelve_nc' : ($has('twelve_nc_code') ? 'twelve_nc_code' : null);
$activeField    = $has('active') ? 'active' : ($has('status') ? 'status' : null);
$categoryField  = $has('category_id') ? 'category_id' : null;
$createdAtField = $has('created_at') ? 'created_at' : null;
$updatedAtField = $has('updated_at') ? 'updated_at' : null;

// WAGA (aliasy)
$weightField = null; foreach (['weight','weight_kg','mass','mass_kg'] as $wf){ if($has($wf)){ $weightField=$wf; break; } }
// STAN (aliasy – docelowa kolumna)
$stockField = null; foreach (['stock','stock_qty','stock_cached'] as $sf){ if($has($sf)){ $stockField=$sf; break; } }

if (!$codeField) { header('Location: bulk_add.php?msg=error&reason=no_code_column'); exit; }

/* ——— Debug payload ——— */
if (isset($_GET['debug']) && $_GET['debug']==='1') {
  $firstRid = !empty($_POST['row_id']) && is_array($_POST['row_id']) ? array_keys($_POST['row_id'])[0] ?? null : null;
  $sample = $firstRid ? [
    'name'       => $_POST['name'][$firstRid] ?? null,
    'code'       => $_POST['code'][$firstRid] ?? null,
    'sku'        => $_POST['sku'][$firstRid]  ?? null,
    'price'      => $_POST['price'][$firstRid] ?? ($_POST['unit_price'][$firstRid] ?? null),
    'stock'      => $_POST['stock'][$firstRid] ?? ($_POST['stock_qty'][$firstRid] ?? null),
    'vat_rate'   => $_POST['vat_rate'][$firstRid] ?? ($_POST['vat'][$firstRid] ?? null),
    'weight'     => $_POST['weight'][$firstRid] ?? $_POST['weight_kg'][$firstRid] ?? $_POST['mass'][$firstRid] ?? $_POST['mass_kg'][$firstRid] ?? null,
    'twelve_nc'  => $_POST['twelve_nc'][$firstRid] ?? ($_POST['twelve_nc_code'][$firstRid] ?? null),
    'active'     => isset($_POST['active'][$firstRid]) ? 1 : 0,
    'category_id'=> $_POST['category_id'][$firstRid] ?? null,
  ] : null;
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'owner_id'=>$ownerId,
    'detected_columns'=>array_keys($cols),
    'map'=>compact('codeField','priceField','vatField','weightField','twelveField','activeField','categoryField','stockField','createdAtField','updatedAtField'),
    'rows_count'=> is_array($_POST['row_id'] ?? null) ? count($_POST['row_id']) : 0,
    'first_row_id'=>$firstRid,
    'first_row_sample'=>$sample
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit;
}

/* ——— Tryby ——— */
$existingAction = $_POST['existing_action'] ?? 'upsert'; // upsert|update_only
$stockMode      = $_POST['stock_mode']      ?? 'set';    // set|increment

/* ——— Row IDs ——— */
$rowIds = array_keys($_POST['row_id'] ?? []);
if (!$rowIds){ header('Location: bulk_add.php?msg=error&reason=no_rows'); exit; }

/* ——— Helpers ——— */
// SELECT product by owner+code
$selByCode = $pdo->prepare("SELECT id FROM products WHERE owner_id=:owner AND {$codeField}=:code LIMIT 1");

// fallback SQL insert/update gdy PE brak
function sqlInsertProduct(PDO $pdo, int $ownerId, array $data): int {
  $fields=['owner_id','name',$data['_codeField']];
  foreach (['_priceField','_vatField','_weightField','_twelveField','_activeField','_categoryField','_createdAtField','_updatedAtField'] as $k){ if(!empty($data[$k])) $fields[]=$data[$k]; }
  $sql="INSERT INTO products (".implode(',',$fields).") VALUES (".implode(',',array_map(fn($f)=>":$f",$fields)).")";
  $st=$pdo->prepare($sql);
  $params=['owner_id'=>$ownerId,'name'=>$data['name'],$data['_codeField']=>$data['code']];
  foreach (['_priceField','_vatField','_weightField','_twelveField','_activeField','_categoryField','_createdAtField','_updatedAtField'] as $k){
    if(!empty($data[$k])){ $key=$data[$k]; $params[$key]=$data[$key] ?? null; }
  }
  $st->execute($params);
  return (int)$pdo->lastInsertId();
}
function sqlUpdateProduct(PDO $pdo, int $ownerId, int $id, array $data): void {
  $set=['name=:name'];
  foreach (['_priceField','_vatField','_weightField','_twelveField','_activeField','_categoryField'] as $k){ if(!empty($data[$k])) $set[]=$data[$k].'= :'.$data[$k]; }
  if(!empty($data['_updatedAtField'])) $set[]=$data['_updatedAtField'].'=NOW()';
  $sql="UPDATE products SET ".implode(', ',$set)." WHERE id=:id AND owner_id=:owner";
  $st=$pdo->prepare($sql);
  $params=['id'=>$id,'owner'=>$ownerId,'name'=>$data['name']];
  foreach (['_priceField','_vatField','_weightField','_twelveField','_activeField','_categoryField'] as $k){ if(!empty($data[$k])) { $key=$data[$k]; $params[$key]=$data[$key] ?? null; } }
  $st->execute($params);
}

/** Stock przez StockEngine (lock, FOR UPDATE, set/inc) – fallback SQL */
function applyStock(PDO $pdo, ?object $SE, int $ownerId, int $productId, ?int $qty, string $mode, ?string $stockField, ?string $updatedAtField): void {
  if ($qty === null || !$stockField) return;

  $setSql = $pdo->prepare("UPDATE products SET {$stockField}=:v".($updatedAtField? ", {$updatedAtField}=NOW()" : "")." WHERE id=:id AND owner_id=:oid");
  $incSql = $pdo->prepare("UPDATE products SET {$stockField}=COALESCE({$stockField},0)+:d".($updatedAtField? ", {$updatedAtField}=NOW()" : "")." WHERE id=:id AND owner_id=:oid");

  $deltaForMovement = 0;
  $current = 0;

  if ($SE) {
    $locked = false;
    try {
      $locked = $SE->acquireLock($productId, 3);
      if (!$locked) throw new \RuntimeException('lock timeout');

      $pdo->beginTransaction();
      $st = $pdo->prepare("SELECT {$stockField} AS stock FROM products WHERE id=:id AND owner_id=:oid FOR UPDATE");
      $st->execute(['id'=>$productId,'oid'=>$ownerId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new \RuntimeException('product not found');
      $current = (int)($row['stock'] ?? 0);

      if ($mode === 'set') {
        $deltaForMovement = $qty - $current;
        $setSql->execute(['v'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
      } else {
        $deltaForMovement = $qty;
        $incSql->execute(['d'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
      }

      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // fallback SQL bez locka
      if ($mode === 'set') {
        // potrzebujemy current do delta — spróbujmy odczytać poza transakcją
        $st = $pdo->prepare("SELECT {$stockField} AS stock FROM products WHERE id=:id AND owner_id=:oid");
        $st->execute(['id'=>$productId,'oid'=>$ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC); $current = (int)($row['stock'] ?? 0);
        $deltaForMovement = $qty - $current;
        $setSql->execute(['v'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
      } else {
        $deltaForMovement = $qty;
        $incSql->execute(['d'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
      }
    } finally {
      if ($SE && $locked) { try { $SE->releaseLock($productId); } catch (\Throwable $__) {} }
    }
  } else {
    // brak SE — czysty SQL
    if ($mode === 'set') {
      $st = $pdo->prepare("SELECT {$stockField} AS stock FROM products WHERE id=:id AND owner_id=:oid");
      $st->execute(['id'=>$productId,'oid'=>$ownerId]);
      $row = $st->fetch(PDO::FETCH_ASSOC); $current = (int)($row['stock'] ?? 0);
      $deltaForMovement = $qty - $current;
      $setSql->execute(['v'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
    } else {
      $deltaForMovement = $qty;
      $incSql->execute(['d'=>$qty,'id'=>$productId,'oid'=>$ownerId]);
    }
  }

  // ❤️ ruch do stock_movements (jeśli tabela istnieje)
  if ($deltaForMovement !== 0) {
    $userId = (int)($_SESSION['user']['id'] ?? 0) ?: null;
    $note = 'bulk_add:'.$mode;
    addStockMovementIfPossible($pdo, $ownerId, $productId, (int)$deltaForMovement, $mode, $userId, $note);
  }
}

/**
 * Zapisz ruch magazynowy do stock_movements (o ile tabela/kolumny istnieją).
 * $mode: 'set' lub 'increment'
 * $delta: zmiana ilości (dla set = target-current; dla increment = przyrost)
 */
function addStockMovementIfPossible(PDO $pdo, int $ownerId, int $productId, int $delta, string $mode, ?int $userId = null, ?string $note = null): void {
  try {
    // czy jest tabela?
    $probe = $pdo->query("SHOW TABLES LIKE 'stock_movements'");
    if (!$probe || $probe->rowCount() === 0) return;

    // wykryj kolumny
    $cols = [];
    foreach (($pdo->query("SHOW COLUMNS FROM stock_movements")->fetchAll(PDO::FETCH_ASSOC) ?: []) as $c) {
      $cols[strtolower($c['Field'])] = true;
    }
    $has = fn(string $n): bool => isset($cols[strtolower($n)]);

    // minimalny zestaw
    $fields = [];
    $values = [];
    $params = [];

    if ($has('owner_id'))    { $fields[]='owner_id';    $values[]=':owner_id';    $params['owner_id']=$ownerId; }
    if ($has('product_id'))  { $fields[]='product_id';  $values[]=':product_id';  $params['product_id']=$productId; }

    // ilość/delta — próbujemy kilku nazw
    $qtyCol = null;
    foreach (['delta','qty','quantity','change','amount'] as $q) {
      if ($has($q)) { $qtyCol = $q; break; }
    }
    if ($qtyCol) { $fields[]=$qtyCol; $values[]=':delta'; $params['delta']=$delta; }

    // tryb/typ/powód
    $modeCol = null;
    foreach (['mode','type','reason','source'] as $m) {
      if ($has($m)) { $modeCol = $m; break; }
    }
    if ($modeCol) { $fields[]=$modeCol; $values[]=':mode'; $params['mode']=$mode; }

    // user/operator (jeśli trzymacie)
    if ($userId !== null) {
      foreach (['user_id','operator_id','author_id'] as $u) {
        if ($has($u)) { $fields[]=$u; $values[]=':uid'; $params['uid']=$userId; break; }
      }
    }

    // notatka (opcjonalnie)
    if ($note !== null) {
      foreach (['note','notes','comment','description'] as $n) {
        if ($has($n)) { $fields[]=$n; $values[]=':note'; $params['note']=$note; break; }
      }
    }

    // timestamp
    if ($has('created_at')) { $fields[]='created_at'; $values[]='NOW()'; }
    if ($has('created_by')) {
      // czasem projekty mają created_by — jeśli mamy usera, spróbujmy go podać
      if (!isset($params['uid']) && $userId !== null) { $fields[]='created_by'; $values[]=':cby'; $params['cby']=$userId; }
    }

    if (!$fields) return; // nic sensownego do zapisania

    $sql = "INSERT INTO stock_movements (".implode(',', $fields).") VALUES (".implode(',', $values).")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  } catch (\Throwable $__) {
    // cicho — ruch jest „best-effort”, nie blokujemy całego importu
  }
}

/* ——— Główna pętla ——— */
$ins=0; $upd=0; $skip=0;
$pdo->beginTransaction();
try {
  foreach (array_keys($_POST['row_id']) as $rid) {
    try {
      $name = trim($_POST['name'][$rid] ?? '');
      $code = trim($_POST[$codeField][$rid] ?? ($_POST['code'][$rid] ?? $_POST['sku'][$rid] ?? ''));
      if ($name === '' || $code === '') { $skip++; continue; }

      $price     = $_POST['price'][$rid]      ?? $_POST['unit_price'][$rid] ?? '';
      $vat_rate  = $_POST['vat_rate'][$rid]   ?? $_POST['vat'][$rid] ?? 23;
      $stockVal  = $_POST['stock'][$rid]      ?? $_POST['stock_qty'][$rid] ?? '';
      $weightIn  = $_POST['weight'][$rid]     ?? $_POST['weight_kg'][$rid] ?? $_POST['mass'][$rid] ?? $_POST['mass_kg'][$rid] ?? '';
      $twelve_nc = trim($_POST['twelve_nc'][$rid] ?? $_POST['twelve_nc_code'][$rid] ?? '');
      $active    = isset($_POST['active'][$rid]) ? 1 : 0;
      $category  = $_POST['category_id'][$rid] ?? null;
      $category  = ($category === '' ? null : (is_numeric($category) ? (int)$category : null));

      $price     = ($price     === '' ? null : (float)$price);
      $vat_val   = (int)$vat_rate;
      $weightVal = ($weightIn  === '' ? null : (float)$weightIn);
      $stockInt  = ($stockVal  === '' ? null : (int)$stockVal);

      // Payload dla PE/SQL
      $data = [
        'name'=>$name, 'code'=>$code,
        '_codeField'=>$codeField,
        '_priceField'=>$priceField, '_vatField'=>$vatField, '_weightField'=>$weightField,
        '_twelveField'=>$twelveField, '_activeField'=>$activeField, '_categoryField'=>$categoryField,
        '_createdAtField'=>$createdAtField, '_updatedAtField'=>$updatedAtField
      ];
      if ($priceField)    $data[$priceField]    = $price;
      if ($vatField)      $data[$vatField]      = $vat_val;
      if ($weightField)   $data[$weightField]   = $weightVal;
      if ($twelveField)   $data[$twelveField]   = $twelve_nc;
      if ($activeField)   $data[$activeField]   = ($activeField==='status' ? ($active?'active':'inactive') : $active);
      if ($categoryField) $data[$categoryField] = $category;
      if ($createdAtField)$data[$createdAtField]= date('Y-m-d H:i:s');
      if ($updatedAtField)$data[$updatedAtField]= date('Y-m-d H:i:s');

      // Czy istnieje?
      $selByCode->execute(['owner'=>$ownerId,'code'=>$code]);
      $existing = $selByCode->fetch(PDO::FETCH_ASSOC);
      $pid = null;

      if ($existing) {
        if ($existingAction === 'update_only' || $existingAction === 'upsert') {
          $done=false;
          if ($PE && method_exists($PE,'update')) {
            try { $PE->update((int)$existing['id'], $ownerId, $data); $done=true; } catch(Throwable $__) {}
          }
          if (!$done) sqlUpdateProduct($pdo, $ownerId, (int)$existing['id'], $data);
          $pid = (int)$existing['id']; $upd++;
        } else { $skip++; continue; }
      } else {
        if ($existingAction === 'update_only') { $skip++; continue; }
        $done=false;
        if ($PE && method_exists($PE,'create')) {
          try { $pid = (int)$PE->create($ownerId, $data); $done=true; } catch(Throwable $__) {}
        }
        if (!$done) $pid = sqlInsertProduct($pdo, $ownerId, $data);
        $ins++;
      }

      // Zmiana stanu przez StockEngine (poza transakcją główną, żeby nie trzymać locków)
      if ($stockInt !== null && $stockField) {
        $pdo->commit();
        applyStock($pdo, $SE, $ownerId, (int)$pid, $stockInt, $stockMode, $stockField, $updatedAtField);
        $pdo->beginTransaction();
      }

      // Zdjęcia (prosty zapis pliku + wpis do product_images jeżeli tabela istnieje)
      if (isset($_FILES['main_image']['name'][$rid]) && $_FILES['main_image']['name'][$rid] !== '' && is_uploaded_file($_FILES['main_image']['tmp_name'][$rid])) {
        $savedUrl = saveProductImageFile($ownerId, (int)$pid, $_FILES['main_image']['tmp_name'][$rid], $_FILES['main_image']['name'][$rid]);
        if ($savedUrl) attachImage($pdo, (int)$pid, $ownerId, $savedUrl, true);
      }
      if (!empty($_FILES['gallery']['name'][$rid]) && is_array($_FILES['gallery']['name'][$rid])) {
        $names = $_FILES['gallery']['name'][$rid];
        $tmps  = $_FILES['gallery']['tmp_name'][$rid];
        for ($i=0; $i<count($names); $i++){
          if ($names[$i]!=='' && is_uploaded_file($tmps[$i])) {
            $url = saveProductImageFile($ownerId, (int)$pid, $tmps[$i], $names[$i]);
            if ($url) attachImage($pdo, (int)$pid, $ownerId, $url, false);
          }
        }
      }

    } catch (Throwable $rowEx) {
      $skip++;
      if (function_exists('logg')) logg('warning','products.bulk_add','Row error — skipped',[ 'owner_id'=>$ownerId,'row_id'=>$rid,'err'=>$rowEx->getMessage() ]);
    }
  }

  $pdo->commit();
  if (function_exists('logg')) logg('info','products.bulk_add','Bulk save OK',[ 'owner_id'=>$ownerId,'ins'=>$ins,'upd'=>$upd,'skip'=>$skip,'use_PE'=>(bool)$PE,'use_SE'=>(bool)$SE, 'map'=>['weightField'=>$weightField,'stockField'=>$stockField] ]);
  header('Location: bulk_add.php?msg=ok&ins='.$ins.'&upd='.$upd.'&skip='.$skip); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (function_exists('logg')) logg('error','products.bulk_add','Bulk save ERROR',[ 'owner_id'=>$ownerId,'ins'=>$ins,'upd'=>$upd,'skip'=>$skip,'err'=>$e->getMessage() ]);
  header('Location: bulk_add.php?msg=error&reason=exception'); exit;
}

/* ——— Files & images ——— */
function saveProductImageFile(int $ownerId, int $productId, string $tmp, string $origName): ?string {
  $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','gif','avif'])) $ext = 'jpg';
  $baseDir = __DIR__ . '/../../uploads/products/' . $ownerId . '/' . $productId;
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);
  $fname = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest  = $baseDir . '/' . $fname;
  if (!@move_uploaded_file($tmp, $dest)) return null;
  return '/uploads/products/' . $ownerId . '/' . $productId . '/' . $fname;
}
function attachImage(PDO $pdo, int $productId, int $ownerId, string $url, bool $isMain): void {
  try {
    $probe = $pdo->query("SHOW TABLES LIKE 'product_images'");
    if (!$probe || $probe->rowCount()===0) return;
    $cols=[]; foreach($pdo->query("SHOW COLUMNS FROM product_images")->fetchAll(PDO::FETCH_ASSOC) as $c){ $cols[strtolower($c['Field'])]=true; }
    $has = fn(string $f)=>isset($cols[strtolower($f)]);
    $fields=['owner_id','product_id']; $values=[':owner_id',':product_id']; $params=['owner_id'=>$ownerId,'product_id'=>$productId];
    if ($has('url'))       { $fields[]='url';       $values[]=':url';       $params['url']=$url; }
    elseif ($has('path'))  { $fields[]='path';      $values[]=':url';       $params['url']=$url; }
    if ($has('is_main'))   { $fields[]='is_main';   $values[]=':is_main';   $params['is_main']=$isMain?1:0; }
    if ($has('created_at')){ $fields[]='created_at';$values[]='NOW()'; }
    $sql="INSERT INTO product_images (".implode(',',$fields).") VALUES (".implode(',',$values).")";
    $pdo->prepare($sql)->execute($params);
  } catch(Throwable $__) {}
}
