<?php
// admin/purchases/import.php — Olaj.pl V4 (PDF/CSV/PASTE + PDF.js fallback)
// 1. opis czynności lub funkcji
// Import faktury zakupu z PDF/CSV/wklejki. Logowanie: olaj_v4_logger (logg()).

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';               // <<< logger
require_once __DIR__ . '/../../includes/pdf_invoice_parser.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf       = $_SESSION['csrf_token'];
$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
if (!$owner_id) { echo '<div class="max-w-3xl mx-auto p-6">❌ Brak dostępu.</div>'; require_once __DIR__.'/../../layout/layout_footer.php'; exit; }

// 2) helpery
function dec($v){ if($v===null)return null; $v=str_replace(["\xC2\xA0",' '],'',trim((string)$v)); $v=str_replace(',','.',$v); return is_numeric($v)?(float)$v:null; }
function iqty($v){ $v=str_replace(["\xC2\xA0",' '],'',trim((string)$v)); if($v==='')return 0; $v=str_replace(',','.',$v); return (int)round((float)$v); }
function normstr($v){ $v=trim((string)$v); return $v===''?null:$v; }
function normalize_header($arr){ $map=[]; foreach($arr as $i=>$h){ $k=mb_strtolower(trim($h)); $k=str_replace([' ','-','.'] ,'_',$k);
  if (in_array($k,['ean','barcode','kod_kreskowy'])) $k='barcode';
  if (in_array($k,['sku','symbol','indeks'])) $k='supplier_sku';
  if (in_array($k,['12nc','twelve_nc','kod_12nc','nr_12nc'])) $k='external_12nc';
  if (in_array($k,['ilosc','ilość','qty'])) $k='qty';
  if (in_array($k,['netto','unit_net','cena_netto'])) $k='unit_net';
  if (in_array($k,['vat','vat_rate','stawka_vat'])) $k='vat_rate';
  if (in_array($k,['nazwa','name_produktu'])) $k='name';
  if (in_array($k,['code','kod'])) $k='code'; $map[$i]=$k; } return $map; }
function detect_delim($line){ $c=[';'=>substr_count($line,';'),','=>substr_count($line,','),"\t"=>substr_count($line,"\t"),'|'=>substr_count($line,'|')]; arsort($c); $k=array_key_first($c); return $c[$k]>0?$k:','; }
function parse_rows_csv_or_paste($raw, $is_file=false){
  $rows=[]; $content=$is_file?file_get_contents($raw):(string)$raw; if(!$content) return $rows;
  $lines=preg_split("/\r\n|\n|\r/",$content); if(!$lines) return $rows;
  $del=detect_delim($lines[0]); $first=str_getcsv($lines[0],$del); $hdr_map=normalize_header($first);
  $has_header = count(array_intersect($hdr_map, ['name','qty','unit_net','vat_rate','barcode','external_12nc','supplier_sku','code']))>=2;
  $start=$has_header?1:0; $fallback=['name','qty','unit_net','vat_rate','barcode','external_12nc','supplier_sku'];
  for($i=$start;$i<count($lines);$i++){
    $ln=$lines[$i]; if(trim($ln)==='') continue; $cols=str_getcsv($ln,$del); $row=[];
    if($has_header){ foreach($cols as $idx=>$val){ $key=$hdr_map[$idx]??null; if($key) $row[$key]=$val; } }
    else { foreach($fallback as $idx=>$key){ $row[$key]=$cols[$idx]??null; } }
    if (!isset($row['name']) || trim((string)$row['name'])==='') continue;
    $rows[]=$row;
  }
  return $rows;
}
function find_product(PDO $pdo, int $owner_id, array $r): array {
  $barcode = normstr($r['barcode'] ?? null);
  $code    = normstr($r['code'] ?? null);
  $sku     = normstr($r['supplier_sku'] ?? null);
  $n12     = normstr($r['external_12nc'] ?? null);
  if ($barcode) { $st=$pdo->prepare("SELECT id,code FROM products WHERE owner_id=? AND barcode=? LIMIT 1"); $st->execute([$owner_id,$barcode]); if($p=$st->fetch(PDO::FETCH_ASSOC)) return [$p['id'],'barcode',$p['code']]; }
  if ($code)    { $st=$pdo->prepare("SELECT id,code FROM products WHERE owner_id=? AND code=? LIMIT 1");       $st->execute([$owner_id,$code]);    if($p=$st->fetch(PDO::FETCH_ASSOC)) return [$p['id'],'code',$p['code']]; }
  if ($sku)     { $st=$pdo->prepare("SELECT id,code FROM products WHERE owner_id=? AND sku=? LIMIT 1");        $st->execute([$owner_id,$sku]);     if($p=$st->fetch(PDO::FETCH_ASSOC)) return [$p['id'],'sku',$p['code']]; }
  if ($n12)     { $st=$pdo->prepare("SELECT p.id,p.code FROM twelve_nc_map m JOIN products p ON p.id=m.product_id WHERE m.owner_id=? AND m.external_12nc=? LIMIT 1"); $st->execute([$owner_id,$n12]); if($p=$st->fetch(PDO::FETCH_ASSOC)) return [$p['id'],'12nc',$p['code']]; }
  return [null,'manual',null];
}

// 3) POST — import
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $post_csrf = $_POST['csrf'] ?? '';
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$post_csrf)) {
    logg('warning','purchases.import','CSRF mismatch',[]);
    echo '<div class="max-w-3xl mx-auto p-6">❌ CSRF.</div>'; require_once __DIR__.'/../../layout/layout_footer.php'; exit;
  }

  $supplier_id  = (int)($_POST['supplier_id'] ?? 0);
  $invoice_no   = trim((string)($_POST['invoice_no'] ?? ''));
  $invoice_date = trim((string)($_POST['invoice_date'] ?? date('Y-m-d')));
  $currency     = strtoupper(trim((string)($_POST['currency'] ?? 'PLN')));
  $fx_rate      = dec($_POST['fx_rate'] ?? 1.0);
  $paste        = trim((string)($_POST['paste'] ?? ''));
  $from_browser_pdf = (int)($_POST['from_browser_pdf'] ?? 0);

  logg('info','purchases.import','Start importu',[
    'supplier_id'=>$supplier_id,'invoice_no'=>$invoice_no,'currency'=>$currency,'fx_rate'=>$fx_rate,
    'from_browser_pdf'=>$from_browser_pdf,'has_pdf'=>!empty($_FILES['pdf']['tmp_name']),'has_csv'=>!empty($_FILES['csv']['tmp_name'])
  ]);

  if (!$supplier_id || $invoice_no===''){
    logg('warning','purchases.import','Brak wymaganych pól',['supplier_id'=>$supplier_id,'invoice_no'=>$invoice_no]);
    echo '<div class="max-w-3xl mx-auto p-6">❌ Wymagane: dostawca i numer faktury.</div>'; require_once __DIR__.'/../../layout/layout_footer.php'; exit;
  }
  if ($currency!=='PLN' && (!$fx_rate || $fx_rate<=0)){
    logg('warning','purchases.import','Zły kurs dla waluty ≠ PLN',['currency'=>$currency,'fx_rate'=>$fx_rate]);
    echo '<div class="max-w-3xl mx-auto p-6">❌ Dla waluty ≠ PLN ustaw kurs.</div>'; require_once __DIR__.'/../../layout/layout_footer.php'; exit;
  }

  $rows = []; $file_hash = '';

  // A) Tekst z przeglądarki (PDF.js)
  if ($from_browser_pdf && $paste!=='') {
    $file_hash = sha1($paste);
    logg('info','purchases.import','Tekst PDF z przeglądarki',['len'=>strlen($paste)]);
    $rows = olaj_parse_pdf_text_to_rows($paste);
  }
  // B) PDF na serwerze
  elseif (!empty($_FILES['pdf']['tmp_name']) && is_uploaded_file($_FILES['pdf']['tmp_name'])) {
    $pdf = $_FILES['pdf']['tmp_name'];
    $file_hash = sha1_file($pdf) ?: '';
    logg('debug','purchases.import','PDF upload OK — parsuję serwerowo',['tmp'=>$pdf]);
    $rows = olaj_parse_pdf_invoice_to_rows($pdf);
    if (empty($rows)) {
      $text = olaj_pdf_extract_text($pdf);
      logg('debug','purchases.import','Retry: parser z samego tekstu',['len'=>strlen($text)]);
      if ($text !== '') $rows = olaj_parse_pdf_text_to_rows($text);
    }
  }
  // C) CSV/wklejka
  elseif (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
    $tmp = $_FILES['csv']['tmp_name'];
    $file_hash = sha1_file($tmp) ?: '';
    logg('debug','purchases.import','CSV upload OK',['tmp'=>$tmp]);
    $rows = parse_rows_csv_or_paste($tmp, true);
  } elseif ($paste!=='') {
    $file_hash = sha1($paste);
    logg('debug','purchases.import','Wklejka CSV/TSV',['len'=>strlen($paste)]);
    $rows = parse_rows_csv_or_paste($paste, false);
  } else {
    logg('warning','purchases.import','Brak danych wejściowych',[]);
    echo '<div class="max-w-3xl mx-auto p-6">❌ Brak danych do importu (PDF/CSV/wklejka).</div>'; require_once __DIR__ . '/../../layout/layout_footer.php'; exit;
  }

  if (empty($rows)) {
    logg('warning','purchases.import','Brak pozycji po parsowaniu',[
      'mode'=> $from_browser_pdf ? 'browser_text' : (!empty($_FILES['pdf']['tmp_name']) ? 'server_pdf' : 'csv_or_paste')
    ]);
    echo '<div class="max-w-3xl mx-auto p-6 space-y-3">
      <div>❌ Nie udało się odczytać pozycji z dokumentu.</div>
      <div class="text-gray-700">Spróbuj ponownie, albo użyj „Odczytaj PDF w przeglądarce (beta)”.</div>
    </div>';
    require_once __DIR__ . '/../../layout/layout_footer.php'; exit;
  }

  // import do DB
  $unique_hash = sha1(json_encode([$owner_id,$supplier_id,$invoice_no,$invoice_date,$file_hash]));
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare("SELECT id FROM purchase_invoices WHERE owner_id=? AND unique_hash=? LIMIT 1 FOR UPDATE");
    $q->execute([$owner_id,$unique_hash]);
    if ($q->fetchColumn()) throw new RuntimeException('Duplikat faktury (unique_hash).');

    logg('debug','purchases.import','Wstawiam nagłówek + pozycje',['items_count'=>count($rows)]);

    $insInv = $pdo->prepare("INSERT INTO purchase_invoices (owner_id,supplier_id,invoice_no,invoice_date,currency,fx_rate,status,unique_hash,created_at)
                             VALUES (?,?,?,?,?,?, 'imported', ?, NOW())");
    $insInv->execute([$owner_id,$supplier_id,$invoice_no,$invoice_date,$currency,$fx_rate,$unique_hash]);
    $purchase_id = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare("
      INSERT INTO purchase_invoice_items
        (purchase_id, product_id, name, supplier_sku, external_12nc, barcode, qty, unit_net, vat_rate, matched_by, status, code_status, created_product, created_at)
      VALUES (?,?,?,?,?,?,?,?,?, ?, ?, 'none', 0, NOW())
    ");

    $sum_net=$sum_vat=$sum_gross=0.0; $count=0;
    foreach ($rows as $r) {
      $name = trim((string)($r['name'] ?? '')); if ($name==='') continue;
      $qty  = iqty($r['qty'] ?? 0);
      $net  = dec($r['unit_net'] ?? 0);
      $vatr = dec($r['vat_rate'] ?? 23.0);
      if ($qty<=0 || $net===null) continue; if ($vatr===null) $vatr=23.0;

      [$pid, $matched_by, $prod_code] = find_product($pdo, $owner_id, $r);
      $status      = $pid ? 'matched' : 'new';
      $code_status = $pid ? ((isset($prod_code)&&$prod_code!=='')?'confirmed':'none') : 'none';

      $insItem->execute([
        $purchase_id,
        $pid,
        $name,
        normstr($r['supplier_sku'] ?? null),
        normstr($r['external_12nc'] ?? null),
        normstr($r['barcode'] ?? null),
        $qty,
        $net,
        $vatr,
        $matched_by,
        $status,
        $code_status
      ]);

      $line_net   = $net * $qty;
      $line_vat   = round($line_net * ($vatr/100), 2);
      $line_gross = $line_net + $line_vat;

      $sum_net   += $line_net;
      $sum_vat   += $line_vat;
      $sum_gross += $line_gross;
      $count++;
    }

    if ($count===0) throw new RuntimeException('Brak poprawnych pozycji po parsowaniu.');

    $pdo->prepare("UPDATE purchase_invoices SET total_net=?, total_vat=?, total_gross=?, updated_at=NOW() WHERE id=?")
        ->execute([round($sum_net,2), round($sum_vat,2), round($sum_gross,2), $purchase_id]);

    $pdo->commit();
    logg('info','purchases.import','Import OK',[
      'purchase_id'=>$purchase_id,'items'=>$count,'total_net'=>$sum_net,'total_vat'=>$sum_vat,'total_gross'=>$sum_gross
    ]);
    header("Location: center.php?import_ok=1&pid=".$purchase_id);
    exit;

  } catch (Throwable $e) {
    $pdo->rollBack();
    logg('error','purchases.import','Błąd importu',['error'=>$e->getMessage()]);
    echo '<div class="max-w-3xl mx-auto p-6">❌ Błąd importu: '.htmlspecialchars($e->getMessage()).'</div>';
    require_once __DIR__ . '/../../layout/layout_footer.php'; exit;
  }
}

// 4) GET — formularz
$st = $pdo->prepare("SELECT id, name FROM suppliers WHERE owner_id=? ORDER BY name ASC");
$st->execute([$owner_id]);
$suppliers = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <div class="bg-white rounded-2xl shadow p-6">
    <h1 class="text-2xl font-semibold mb-4">Import faktury zakupu (PDF / CSV / Wklejka)</h1>
    <form method="post" enctype="multipart/form-data" class="space-y-4" id="importForm">
      <input type="hidden" name="csrf" value="<?=$csrf?>">
      <input type="hidden" name="from_browser_pdf" id="from_browser_pdf" value="0">
      <textarea name="paste" id="paste" class="hidden"></textarea><!-- wypełni PDF.js -->

      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div class="md:col-span-2">
          <label class="block text-sm mb-1">Dostawca</label>
          <select name="supplier_id" class="w-full border rounded px-3 py-2" required>
            <option value="">— wybierz —</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm mb-1">Nr faktury</label>
          <input type="text" name="invoice_no" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block text-sm mb-1">Data</label>
          <input type="date" name="invoice_date" value="<?=date('Y-m-d')?>" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
          <label class="block text-sm mb-1">Waluta</label>
          <select name="currency" id="currency" class="w-full border rounded px-3 py-2">
            <?php foreach (['PLN','EUR','USD','GBP'] as $c): ?>
              <option value="<?=$c?>" <?=$c==='PLN'?'selected':''?>><?=$c?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <div>
          <label class="block text-sm mb-1">Kurs (PLN)</label>
          <input type="number" step="0.000001" name="fx_rate" id="fx_rate" value="1.000000" class="w-full border rounded px-3 py-2">
          <small class="text-gray-500">Dla waluty ≠ PLN</small>
        </div>
        <div class="md:col-span-4 text-gray-600 flex items-end">
          <span>Jeśli serwer nie ma <code>pdftotext</code>/<code>tesseract</code>, użyj przycisku poniżej – odczyta PDF w Twojej przeglądarce.</span>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm mb-1">Plik PDF (preferowane)</label>
          <input type="file" name="pdf" id="pdf" accept="application/pdf,.pdf" class="w-full border rounded px-3 py-2">
          <div class="mt-2 flex gap-2">
            <button type="button" id="parseBrowserBtn" class="px-3 py-2 bg-amber-600 text-white rounded">Odczytaj PDF w przeglądarce (beta)</button>
            <span id="parseStatus" class="text-sm text-gray-600 hidden">⏳ Przetwarzanie…</span>
          </div>
        </div>
        <div>
          <label class="block text-sm mb-1">Albo plik CSV / wklejka</label>
          <input type="file" name="csv" accept=".csv,text/csv" class="w-full border rounded px-3 py-2 mb-2">
          <textarea name="paste_visible" rows="6" class="w-full border rounded px-3 py-2" placeholder="name;qty;unit_net;vat_rate;barcode;external_12nc;supplier_sku (CSV)"></textarea>
          <small class="text-gray-500">Pole wyżej NIE wpływa na PDF – to zwykła alternatywa CSV.</small>
        </div>
      </div>

      <div class="pt-2">
        <button class="px-4 py-2 bg-blue-600 text-white rounded-lg shadow">Importuj</button>
        <a href="center.php" class="px-4 py-2 bg-gray-100 rounded border ml-2">Wróć</a>
      </div>
    </form>
  </div>
</div>

<!-- PDF.js: CDN + fallback lokalny -->
<script>
(function(){
  const CDN_JS = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js";
  const CDN_WORKER = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  const LOCAL_JS = "/assets/pdfjs/pdf.min.js";
  const LOCAL_WORKER = "/assets/pdfjs/pdf.worker.min.js";

  function loadScript(src, onload, onerror){
    var s=document.createElement('script');
    s.src=src; s.async=true; s.onload=onload; s.onerror=onerror;
    document.head.appendChild(s);
  }

  function setWorker(src){
    if (window.pdfjsLib) {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = src;
      return true;
    }
    return false;
  }

  function bootWith(srcJs, srcWorker){
    loadScript(srcJs, function(){
      if(!setWorker(srcWorker)){
        console.warn("pdfjsLib not found to set worker");
      }
      console.log("PDF.js ready:", srcJs);
      window.__PDFJS_READY__ = true;
    }, function(){
      console.error("Failed to load", srcJs);
    });
  }

  // Try CDN first; if not ready in 2s or load error — fallback to local
  let fallbackTimer = setTimeout(function(){
    if (!window.__PDFJS_READY__) {
      console.warn("CDN slow/blocked, switching to LOCAL pdf.js");
      bootWith(LOCAL_JS, LOCAL_WORKER);
    }
  }, 2000);

  loadScript(CDN_JS, function(){
    if (setWorker(CDN_WORKER)) {
      window.__PDFJS_READY__ = true;
      console.log("PDF.js ready (CDN)");
      clearTimeout(fallbackTimer);
    }
  }, function(){
    console.warn("CDN load error, switching to LOCAL");
    clearTimeout(fallbackTimer);
    bootWith(LOCAL_JS, LOCAL_WORKER);
  });
})();
</script>

<script>
  // UX: kurs tylko gdy waluta != PLN
  const cur = document.getElementById('currency');
  const fx  = document.getElementById('fx_rate');
  function toggleFx(){ if(cur.value==='PLN'){ fx.value='1.000000'; fx.readOnly=true; } else { fx.readOnly=false; if(!fx.value||fx.value==='1.000000') fx.value='4.000000'; } }
  cur.addEventListener('change', toggleFx); toggleFx();

  // PDF.js – odczytaj tekst lokalnie i wyślij jako "paste"
  const parseBtn = document.getElementById('parseBrowserBtn');
  const pdfInput = document.getElementById('pdf');
  const pasteHidden = document.getElementById('paste');
  const fromBrowser = document.getElementById('from_browser_pdf');
  const statusEl = document.getElementById('parseStatus');

  parseBtn?.addEventListener('click', async () => {
    if (!pdfInput.files || !pdfInput.files[0]) { alert('Wybierz plik PDF.'); return; }
    statusEl.classList.remove('hidden');
    try {
      const buf = await pdfInput.files[0].arrayBuffer();
      const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
      let all = [];
      for (let p=1; p<=pdf.numPages; p++){
        const page = await pdf.getPage(p);
        const tc = await page.getTextContent();
        const pageText = tc.items.map(it => it.str).join(' ');
        all.push(pageText);
      }
      const text = all.join('\n');
      pasteHidden.value = text;
      fromBrowser.value = '1';
      alert('✅ Tekst z PDF odczytany w przeglądarce. Kliknij „Importuj”.');
    } catch (e){
      console.error(e);
      alert('❌ Nie udało się odczytać PDF w przeglądarce.');
    } finally {
      statusEl.classList.add('hidden');
    }
  });
</script>
<script>
function jslog(level, channel, message, details){
  try {
    fetch('<?= htmlspecialchars('/admin/system/jslog.php', ENT_QUOTES) ?>', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({level, channel, message, details})
    });
  } catch(e){}
}

// Przykład: jeśli pdfjs się nie załadował do 5s — lognij WARNING
setTimeout(function(){
  if (!window.__PDFJS_READY__) {
    jslog('warning', 'pdfjs', 'PDF.js not ready after 5s', {
      location: window.location.href,
      ua: navigator.userAgent
    });
  }
}, 5000);

// Loguj błędy z „Odczytaj PDF w przeglądarce (beta)”
window.addEventListener('olaj_pdf_parse_error', function(e){
  jslog('error', 'pdfjs.parse', e.detail?.message || 'parse error', e.detail || {});
});
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
