<?php
// 1. opis czynności lub funkcji
// Centrum Dostawców — lista faktur zakupowych per dostawca z licznikami statusów
// Filtry: dostawca, status; akcje: Otwórz fakturę, (opcjonalnie) Batch „Generuj kody”

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

// 2. pobierz listę dostawców do filtra
$st = $pdo->prepare("SELECT id, name FROM suppliers WHERE owner_id=? ORDER BY name");
$st->execute([$owner_id]);
$suppliers = $st->fetchAll(PDO::FETCH_ASSOC);

// 3. filtry
$f_supplier = (int)($_GET['supplier_id'] ?? 0);
$f_status   = $_GET['status'] ?? '';

// 4. lista faktur + liczniki
$sql = "
  SELECT
    pi.id, pi.invoice_no, pi.invoice_date, pi.status, pi.total_net, pi.total_vat, pi.total_gross,
    s.name AS supplier_name, s.id AS supplier_id,
    COALESCE(cnt.matched_cnt,0)  AS matched_cnt,
    COALESCE(cnt.new_cnt,0)      AS new_cnt,
    COALESCE(cnt.conflict_cnt,0) AS conflict_cnt,
    COALESCE(cnt.code_none,0)    AS code_none,
    COALESCE(cnt.code_suggested,0) AS code_suggested
  FROM purchase_invoices pi
  JOIN suppliers s ON s.id = pi.supplier_id
  LEFT JOIN (
    SELECT purchase_id,
           SUM(status='matched')  AS matched_cnt,
           SUM(status='new')      AS new_cnt,
           SUM(status='conflict') AS conflict_cnt,
           SUM(code_status='none')      AS code_none,
           SUM(code_status='suggested') AS code_suggested
    FROM purchase_invoice_items
    GROUP BY purchase_id
  ) cnt ON cnt.purchase_id = pi.id
  WHERE pi.owner_id = :oid
";
$params = ['oid'=>$owner_id];

if ($f_supplier) { $sql .= " AND pi.supplier_id = :sid"; $params['sid']=$f_supplier; }
if ($f_status)   { $sql .= " AND pi.status = :st";       $params['st']=$f_status;   }

$sql .= " ORDER BY pi.invoice_date DESC, pi.id DESC LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($params);
$invoices = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="max-w-7xl mx-auto px-4 py-8">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-semibold">Centrum dostawców</h1>
    <a href="import.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg shadow">+ Importuj fakturę</a>
  </div>

  <!-- Filtry -->
  <form method="get" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-3">
    <div>
      <label class="block text-sm mb-1">Dostawca</label>
      <select name="supplier_id" class="w-full border rounded px-3 py-2">
        <option value="0">— wszyscy —</option>
        <?php foreach ($suppliers as $sup): ?>
          <option value="<?=$sup['id']?>" <?= $f_supplier==$sup['id']?'selected':'' ?>>
            <?=htmlspecialchars($sup['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm mb-1">Status</label>
      <select name="status" class="w-full border rounded px-3 py-2">
        <option value="">— wszystkie —</option>
        <?php foreach (['draft','imported','coding','ready','committed'] as $stt): ?>
          <option value="<?=$stt?>" <?= $f_status===$stt?'selected':'' ?>><?=$stt?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="self-end">
      <button class="px-4 py-2 bg-gray-800 text-white rounded">Filtruj</button>
    </div>
  </form>

  <!-- Tabela faktur -->
  <div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left">Dostawca</th>
          <th class="px-4 py-3 text-left">Faktura</th>
          <th class="px-4 py-3">Data</th>
          <th class="px-4 py-3">Wartość</th>
          <th class="px-4 py-3">Matched</th>
          <th class="px-4 py-3">Nowe</th>
          <th class="px-4 py-3">Konflikty</th>
          <th class="px-4 py-3">Do kodu</th>
          <th class="px-4 py-3">Status</th>
          <th class="px-4 py-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($invoices as $row): ?>
        <?php
          $do_kodu = (int)$row['code_none'] + (int)$row['code_suggested'];
          $can_batch = ($do_kodu > 0) && !in_array($row['status'], ['committed','draft']);
        ?>
        <tr class="border-t">
          <td class="px-4 py-3"><?=htmlspecialchars($row['supplier_name'])?></td>
          <td class="px-4 py-3 font-medium"><?=htmlspecialchars($row['invoice_no'])?></td>
          <td class="px-4 py-3"><?=htmlspecialchars($row['invoice_date'])?></td>
          <td class="px-4 py-3 whitespace-nowrap">
            <?= number_format((float)($row['total_gross'] ?? 0), 2, ',', ' ') ?> zł
          </td>
          <td class="px-4 py-3 text-center"><?= (int)$row['matched_cnt'] ?></td>
          <td class="px-4 py-3 text-center"><?= (int)$row['new_cnt'] ?></td>
          <td class="px-4 py-3 text-center">
            <span class="px-2 py-1 rounded text-white <?=((int)$row['conflict_cnt']>0?'bg-red-600':'bg-green-600')?>"><?= (int)$row['conflict_cnt'] ?></span>
          </td>
          <td class="px-4 py-3 text-center"><?= (int)$do_kodu ?></td>
          <td class="px-4 py-3"><span class="px-2 py-1 rounded bg-gray-100"><?=htmlspecialchars($row['status'])?></span></td>
          <td class="px-4 py-3">
            <a class="px-3 py-1 rounded bg-blue-600 text-white" href="invoice.php?id=<?=$row['id']?>">Otwórz</a>
            <?php if ($can_batch): ?>
              <button
                class="px-3 py-1 rounded bg-amber-600 text-white ml-2"
                onclick="batchGen(<?=$row['id']?>)"
              >Generuj kody</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  const CSRF = <?=json_encode($csrf)?>;
  async function batchGen(purchase_id){
    if(!confirm('Wygenerować kody dla brakujących pozycji?')) return;
    const fd = new FormData();
    fd.append('purchase_id', purchase_id);
    fd.append('csrf', CSRF);
    const res = await fetch('ajax_generate_codes.php', { method:'POST', body: fd });
    const data = await res.json();
    if(!data.ok){ alert('❌ '+(data.error||'Błąd')); return; }
    alert('✅ Wygenerowano: '+data.generated+' | Zaktualizowano produkty: '+data.updated_products);
    location.reload();
  }
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
