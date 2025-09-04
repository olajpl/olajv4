<?php
// admin/purchases/invoice.php — Olaj.pl V4
// 1. opis czynności lub funkcji
// Widok pojedynczej faktury zakupu: nagłówek + lista pozycji + akcje (Generuj kody, Commit).
// Zintegrowane z: ajax_generate_codes.php (batch kodów), ajax_commit.php (commit przyjęcia),
// oraz modalnym sugerowaniem kodów przez partials/suggest_codes.php.

// 2. bootstrap
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$owner_id    = (int)($_SESSION['user']['owner_id'] ?? 0);
$purchase_id = (int)($_GET['id'] ?? 0);

// 3. nagłówek faktury
$st = $pdo->prepare("
  SELECT pi.*, s.name AS supplier_name
  FROM purchase_invoices pi
  JOIN suppliers s ON s.id = pi.supplier_id
  WHERE pi.id=? AND pi.owner_id=? LIMIT 1
");
$st->execute([$purchase_id, $owner_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
  echo '<div class="max-w-4xl mx-auto p-6">Nie znaleziono faktury.</div>';
  require_once __DIR__ . '/../../layout/layout_footer.php'; exit;
}

// 4. pozycje faktury
$st = $pdo->prepare("
  SELECT i.*,
         p.code AS product_code, p.sku AS product_sku
  FROM purchase_invoice_items i
  LEFT JOIN products p ON p.id = i.product_id
  WHERE i.purchase_id = ?
  ORDER BY i.id ASC
");
$st->execute([$purchase_id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

// 5. liczniki statusów
$need_codes = 0; $conflicts = 0; $matched = 0; $newc = 0;
foreach ($items as $it) {
  if (in_array($it['code_status'], ['none','suggested'])) $need_codes++;
  if ($it['status']==='conflict') $conflicts++;
  if ($it['status']==='matched')  $matched++;
  if ($it['status']==='new')      $newc++;
}

// 6. filtry (proste na froncie)
$filter_status = $_GET['f_status']  ?? '';
$filter_code   = $_GET['f_code']    ?? '';
?>
<div class="max-w-7xl mx-auto px-4 py-8 space-y-6">
  <!-- Nagłówek -->
  <div class="bg-white rounded-2xl shadow p-5">
    <div class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">Faktura: <?=htmlspecialchars($inv['invoice_no'])?></h1>
        <div class="text-gray-600 mt-1 space-x-2">
          <span>Dostawca: <b><?=htmlspecialchars($inv['supplier_name'])?></b></span>
          <span>• Data: <b><?=htmlspecialchars($inv['invoice_date'])?></b></span>
          <span>• Waluta: <b><?=htmlspecialchars($inv['currency'])?></b></span>
          <span>• Status: <span class="px-2 py-0.5 rounded bg-gray-100"><?=htmlspecialchars($inv['status'])?></span></span>
        </div>
        <div class="text-gray-600 mt-1 space-x-3">
          <span>Matched: <b><?=$matched?></b></span>
          <span>Nowe: <b><?=$newc?></b></span>
          <span>Konflikty: <b class="<?= $conflicts ? 'text-red-600' : 'text-green-700' ?>"><?=$conflicts?></b></span>
          <span>Do kodu: <b class="<?= $need_codes ? 'text-amber-700' : 'text-green-700' ?>"><?=$need_codes?></b></span>
        </div>
      </div>
      <div class="shrink-0 space-x-2">
        <button class="px-3 py-2 rounded bg-amber-600 text-white"
                onclick="batchGen(<?=$purchase_id?>)">Generuj kody (<?=$need_codes?>)</button>
        <button class="px-3 py-2 rounded bg-green-700 text-white"
                onclick="commitInvoice(<?=$purchase_id?>)"
                <?= ($conflicts || $need_codes || $inv['status']==='committed') ? 'disabled' : '' ?>>
          Zatwierdź przyjęcie
        </button>
      </div>
    </div>
  </div>

  <!-- Filtry lokalne -->
  <form method="get" class="bg-white rounded-2xl shadow p-4 grid grid-cols-1 md:grid-cols-5 gap-3">
    <input type="hidden" name="id" value="<?=$purchase_id?>">
    <div>
      <label class="block text-sm mb-1">Status pozycji</label>
      <select name="f_status" class="w-full border rounded px-3 py-2">
        <option value="">— wszystkie —</option>
        <?php foreach (['matched'=>'matched','new'=>'new','conflict'=>'conflict'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= $filter_status===$k?'selected':'' ?>><?=$v?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm mb-1">Status kodu</label>
      <select name="f_code" class="w-full border rounded px-3 py-2">
        <option value="">— wszystkie —</option>
        <?php foreach (['none'=>'none','suggested'=>'suggested','generated'=>'generated','confirmed'=>'confirmed'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= $filter_code===$k?'selected':'' ?>><?=$v?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="self-end">
      <button class="px-4 py-2 bg-gray-800 text-white rounded">Filtruj</button>
    </div>
  </form>

  <!-- Pozycje -->
  <div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left">Nazwa</th>
          <th class="px-4 py-3">EAN/12NC/SKU</th>
          <th class="px-4 py-3">Ilość</th>
          <th class="px-4 py-3">Netto</th>
          <th class="px-4 py-3">VAT%</th>
          <th class="px-4 py-3">Brutto</th>
          <th class="px-4 py-3">Dopas.</th>
          <th class="px-4 py-3">Kod produktu</th>
          <th class="px-4 py-3">Status kodu</th>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($items as $it):
        if ($filter_status && $it['status'] !== $filter_status) continue;
        if ($filter_code   && $it['code_status'] !== $filter_code) continue;

        $badge = [
          'matched' => 'bg-green-600',
          'new' => 'bg-blue-600',
          'conflict' => 'bg-red-600'
        ][$it['status']] ?? 'bg-gray-400';

        $codeBadge = [
          'none'=>'bg-red-100 text-red-700',
          'suggested'=>'bg-amber-100 text-amber-800',
          'generated'=>'bg-blue-100 text-blue-800',
          'confirmed'=>'bg-green-100 text-green-800'
        ][$it['code_status']] ?? 'bg-gray-100';

        $codeDisplay = $it['product_id'] && $it['product_code']
          ? $it['product_code']
          : ($it['suggested_code'] ?: '—');
      ?>
        <tr class="border-t">
          <td class="px-4 py-3 min-w-[360px]"><?=htmlspecialchars($it['name'])?></td>
          <td class="px-4 py-3 text-center">
            <?=htmlspecialchars($it['barcode'] ?: $it['external_12nc'] ?: $it['supplier_sku'] ?: '—')?>
          </td>
          <td class="px-4 py-3 text-center"><?= (int)$it['qty'] ?></td>
          <td class="px-4 py-3 text-right"><?= number_format((float)$it['unit_net'],2,',',' ') ?></td>
          <td class="px-4 py-3 text-center"><?= htmlspecialchars($it['vat_rate']) ?></td>
          <td class="px-4 py-3 text-right"><?= number_format((float)$it['unit_gross'],2,',',' ') ?></td>
          <td class="px-4 py-3 text-center">
            <span class="px-2 py-1 rounded text-white <?=$badge?>"><?=htmlspecialchars($it['status'])?></span>
          </td>
          <td class="px-4 py-3">
            <?php if ($it['status']==='new' || in_array($it['code_status'], ['none','suggested','generated'])): ?>
              <div class="flex items-center gap-2">
                <input type="text"
                       value="<?= htmlspecialchars($codeDisplay) ?>"
                       class="border rounded px-2 py-1 w-44"
                       data-code-input="1"
                       data-item-id="<?=$it['id']?>"
                       placeholder="Kod">
                <button type="button"
                        class="px-2 py-1 rounded bg-amber-600 text-white"
                        onclick="openSuggestModal(this.previousElementSibling)"
                        title="Podpowiedz kod (wg prefixu)">🎯</button>
                <!-- Opcjonalnie można dodać 'Zapisz' po przygotowaniu endpointu ajax_update_item_code.php -->
              </div>
            <?php else: ?>
              <span class="px-2 py-1 rounded bg-gray-100"><?=htmlspecialchars($codeDisplay)?></span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <span class="px-2 py-1 rounded <?=$codeBadge?>"><?=htmlspecialchars($it['code_status'])?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal z listą propozycji kodów -->
<div class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50" id="modal-codes">
  <div class="bg-white p-4 rounded shadow-md max-w-md w-full relative">
    <button onclick="document.getElementById('modal-codes').classList.add('hidden')"
            class="absolute top-2 right-2 text-gray-500 hover:text-black">✖</button>
    <h2 class="text-lg font-semibold mb-2">📋 Sugerowane kody:</h2>
    <div class="flex flex-wrap gap-2 min-h-10" id="suggested-content">⏳ Ładowanie...</div>
  </div>
</div>

<script>
  const CSRF = <?=json_encode($csrf)?>;

  // Batch generowanie kodów (dla brakujących) – korzysta z ajax_generate_codes.php
  async function batchGen(purchase_id){
    if(!confirm('Wygenerować kody dla brakujących pozycji?')) return;
    const fd = new FormData();
    fd.append('purchase_id', purchase_id);
    fd.append('csrf', CSRF);
    const res = await fetch('ajax_generate_codes.php', { method:'POST', body: fd });
    let data; try { data = await res.json(); } catch(e){ alert('❌ Błąd sieci'); return; }
    if(!data.ok){ alert('❌ '+(data.error||'Błąd')); return; }
    alert('✅ Wygenerowano: '+data.generated+' | Zaktualizowano produkty: '+data.updated_products);
    location.reload();
  }

  // Commit przyjęcia – wszystko albo nic – korzysta z ajax_commit.php
  async function commitInvoice(purchase_id){
    // (Opcjonalnie) Zapisz lokalnie wpisane kody zanim uderzymy w commit – dojdzie lekki endpoint.
    // Na teraz commit zakłada, że kody są w DB (wygenerowane batch’em).
    if(!confirm('Zatwierdzić przyjęcie? Operacja wszystko-albo-nic.')) return;

    const fd = new FormData();
    fd.append('purchase_id', purchase_id);
    fd.append('csrf', CSRF);
    const res = await fetch('ajax_commit.php', { method:'POST', body: fd });
    let data; try { data = await res.json(); } catch(e){ alert('❌ Błąd sieci'); return; }
    if(!data.ok){ alert('❌ '+(data.error||'Błąd')); return; }
    alert('✅ Zaksięgowano przyjęcie. Pozycji: '+data.items_committed+' | Utworzono produktów: '+(data.products_created||0));
    window.location.href = 'center.php';
  }

  // Modal „sugester” – korzysta z partials/suggest_codes.php
  function openSuggestModal(inputEl) {
    window.suggestTarget = inputEl;

    // Wyznacz prefix z aktualnej wartości (litery/cyfry od początku)
    let raw = (inputEl.value || '').trim().toUpperCase();
    let m = raw.match(/^([A-Z0-9]+)/);
    let prefix = m ? m[1] : '';

    const modal = document.getElementById('modal-codes');
    const content = document.getElementById('suggested-content');
    modal.classList.remove('hidden');
    content.textContent = '⏳ Ładowanie...';

    // Aktualnie wpisane kody na stronie (by nie dublować)
    const used = Array.from(document.querySelectorAll('input[data-code-input="1"]'))
      .map(el => (el.value||'').trim().toUpperCase())
      .filter(v => v.length > 0)
      .join(',');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'partials/suggest_codes.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function () {
      if (xhr.status === 200) {
        content.innerHTML = xhr.responseText || '<span class="text-gray-500">Brak propozycji</span>';
        document.querySelectorAll('.suggested-code').forEach(el => {
          el.addEventListener('click', () => applySuggestedCode(el.textContent.trim()));
        });
      } else {
        content.textContent = '❌ Błąd: ' + xhr.status;
      }
    };
    xhr.send('prefix=' + encodeURIComponent(prefix) + '&used=' + encodeURIComponent(used));
  }

  function applySuggestedCode(code) {
    if (window.suggestTarget) {
      window.suggestTarget.value = code;
      document.getElementById('modal-codes').classList.add('hidden');
      // Uwaga: na tym etapie kod jest tylko w input’cie (frontend).
      // Jeśli potrzebujesz zapisu do DB „od razu”, dorobimy mini-endpoint ajax_update_item_code.php
      // i wyślemy: item_id (data-item-id) + code (value) + csrf.
    }
  }
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
