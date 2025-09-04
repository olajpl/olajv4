<?php
// show.php – dual: ?id=... => fragment (preview), bez id => dashboard CW

$BASE = dirname(__DIR__, 2);
require_once $BASE . '/includes/auth.php';
require_once $BASE . '/includes/db.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function short($s, $len=90){ $s=(string)$s; return mb_strlen($s)>$len? mb_substr($s,0,$len).'…' : $s; }

// ─────────────────────────────────────────────────────────────
// TRYB A: PODGLĄD POJEDYNCZEJ WIADOMOŚCI (fragment do modału)
// ─────────────────────────────────────────────────────────────
if ($id) {
    $st = $pdo->prepare("SELECT * FROM messages WHERE id=? AND owner_id=? LIMIT 1");
    $st->execute([$id, $owner_id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m){ http_response_code(404); exit('Nie znaleziono'); }

    echo '<div class="grid grid-cols-2 gap-3 mb-3">';
    echo '<div><b>ID:</b> '.(int)($m['id'] ?? 0).'</div>';
    echo '<div><b>Data:</b> '.esc($m['created_at'] ?? '').'</div>';
    echo '<div><b>Status:</b> '.esc($m['status'] ?? '').'</div>';
    echo '<div><b>Kanał:</b> '.esc(($m['channel'] ?? '') ?: ($m['platform'] ?? '')).'</div>';
    echo '<div><b>Client:</b> '.esc($m['client_id'] ?? '').'</div>';
    echo '<div><b>Order:</b> '.esc($m['order_id'] ?? '').'</div>';
    echo '<div><b>Wątek:</b> '.esc($m['platform_thread_id'] ?? '').'</div>';
    echo '<div><b>Kierunek:</b> '.esc($m['direction'] ?? '').'</div>';
    echo '<div class="col-span-2"><b>Temat:</b><br>'.esc($m['subject'] ?? '').'</div>';
    echo '<div class="col-span-2"><b>Treść (text):</b><br><pre class="bg-gray-50 p-2 rounded">'.esc($m['body_text'] ?? '').'</pre></div>';
    echo '<div class="col-span-2"><b>Treść (HTML):</b><br><div class="border rounded p-2">'.(($m['body_html'] ?? '') ?: '<i class="text-gray-500">— brak —</i>').'</div></div>';
    if (!empty($m['error_message'])) {
        echo '<div class="col-span-2"><b>Ostatni błąd:</b><br><pre class="bg-red-50 p-2 rounded">'.esc($m['error_message']).'</pre></div>';
    }
    echo '</div>';
    exit; // fragment only
}

// ─────────────────────────────────────────────────────────────
// TRYB B: LISTA / DASHBOARD
// ─────────────────────────────────────────────────────────────

require_once $BASE . '/layout/top_panel.php';
require_once $BASE . '/layout/layout_header.php';

// Filtry
$q_status   = $_GET['status']   ?? '';
$q_channel  = $_GET['channel']  ?? '';
$q_dir      = $_GET['dir']      ?? '';
$q_client   = (int)($_GET['client_id'] ?? 0);
$q_order    = (int)($_GET['order_id'] ?? 0);
$q_from     = $_GET['from'] ?? '';
$q_to       = $_GET['to']   ?? '';
$q_search   = trim($_GET['q'] ?? '');
$q_limit    = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$q_page     = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($q_page - 1) * $q_limit;

// Query builder (bez kolumny "message" – kompatybilnie)
$where = ["owner_id = ?"];
$params = [$owner_id];

if ($q_status !== '')   { $where[] = "status = ?";       $params[] = $q_status; }
if ($q_channel !== '')  { $where[] = "channel = ?";      $params[] = $q_channel; }
if ($q_dir !== '')      { $where[] = "direction = ?";    $params[] = $q_dir; }
if ($q_client)          { $where[] = "client_id = ?";    $params[] = $q_client; }
if ($q_order)           { $where[] = "order_id = ?";     $params[] = $q_order; }
if ($q_from !== '')     { $where[] = "created_at >= ?";  $params[] = $q_from.' 00:00:00'; }
if ($q_to !== '')       { $where[] = "created_at <= ?";  $params[] = $q_to.' 23:59:59'; }
if ($q_search !== '') {
  // Szukamy po subject/body_text/platform_thread_id (safe – mogą być NULL)
  $where[] = "(COALESCE(body_text,'') LIKE ? OR COALESCE(subject,'') LIKE ? OR COALESCE(platform_thread_id,'') LIKE ?)";
  $like = '%'.$q_search.'%';
  array_push($params, $like, $like, $like);
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Count + data
$sqlCount = "SELECT COUNT(*) FROM messages $whereSql";
$stc = $pdo->prepare($sqlCount); $stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT id, created_at, direction, status, retries, channel, platform, platform_thread_id,
               client_id, order_id, subject, body_text, body_html
        FROM messages
        $whereSql
        ORDER BY id DESC
        LIMIT $q_limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Słowniki
$statuses = ['received','queued','sent','delivered','read','failed'];
$channels = ['messenger','sms','email','live_post','dm','web','other'];
?>
<div class="max-w-7xl mx-auto p-6">
  <h1 class="text-2xl font-semibold mb-4">CW — Wiadomości (dashboard)</h1>

  <form method="get" class="grid md:grid-cols-12 gap-3 mb-5 items-end">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Status</label>
      <select name="status" class="w-full border rounded px-2 py-1">
        <option value="">— wszystkie —</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= esc($s) ?>" <?= $q_status===$s?'selected':'' ?>><?= esc($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Kanał</label>
      <select name="channel" class="w-full border rounded px-2 py-1">
        <option value="">— wszystkie —</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= esc($c) ?>" <?= $q_channel===$c?'selected':'' ?>><?= esc($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Kierunek</label>
      <select name="dir" class="w-full border rounded px-2 py-1">
        <option value="">— oba —</option>
        <option value="in"  <?= $q_dir==='in'?'selected':''  ?>>in</option>
        <option value="out" <?= $q_dir==='out'?'selected':'' ?>>out</option>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Client ID</label>
      <input type="number" name="client_id" value="<?= esc($q_client) ?>" class="w-full border rounded px-2 py-1" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Order ID</label>
      <input type="number" name="order_id" value="<?= esc($q_order) ?>" class="w-full border rounded px-2 py-1" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Od</label>
      <input type="date" name="from" value="<?= esc($q_from) ?>" class="w-full border rounded px-2 py-1" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Do</label>
      <input type="date" name="to" value="<?= esc($q_to) ?>" class="w-full border rounded px-2 py-1" />
    </div>
    <div class="md:col-span-2">
      <label class="block text-sm font-medium mb-1">Szukaj</label>
      <input type="text" name="q" value="<?= esc($q_search) ?>" placeholder="fraza, temat, wątek" class="w-full border rounded px-2 py-1" />
    </div>
    <div>
      <label class="block text-sm font-medium mb-1">Na stronę</label>
      <input type="number" name="limit" value="<?= esc($q_limit) ?>" class="w-full border rounded px-2 py-1" />
    </div>
    <div class="md:col-span-12 flex gap-2">
      <button class="px-3 py-2 rounded bg-black text-white">Filtruj</button>
      <a href="show.php" class="px-3 py-2 rounded border">Wyczyść</a>
      <a href="/admin/logs/index.php?context=cw" class="px-3 py-2 rounded border">Mocne Logi (CW)</a>
    </div>
  </form>

  <div class="overflow-x-auto border rounded">
    <table class="min-w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left p-2">ID</th>
          <th class="text-left p-2">Data</th>
          <th class="text-left p-2">Dir</th>
          <th class="text-left p-2">Status</th>
          <th class="text-left p-2">Kanał</th>
          <th class="text-left p-2">Client</th>
          <th class="text-left p-2">Order</th>
          <th class="text-left p-2">Temat</th>
          <th class="text-left p-2">Treść</th>
          <th class="text-left p-2">Wątek</th>
          <th class="text-left p-2">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="p-3 text-center text-gray-500">Brak wiadomości</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <?php
          $badge = [
            'queued' => 'bg-amber-100 text-amber-800',
            'sent'   => 'bg-green-100 text-green-800',
            'failed' => 'bg-red-100 text-red-800',
            'received' => 'bg-blue-100 text-blue-800',
            'delivered' => 'bg-emerald-100 text-emerald-800',
            'read'   => 'bg-gray-100 text-gray-800'
          ][$r['status'] ?? ''] ?? 'bg-gray-100 text-gray-800';
        ?>
        <tr class="border-t" id="row-<?= (int)($r['id'] ?? 0) ?>">
          <td class="p-2"><?= (int)($r['id'] ?? 0) ?></td>
          <td class="p-2 whitespace-nowrap"><?= esc($r['created_at'] ?? '') ?></td>
          <td class="p-2"><?= esc($r['direction'] ?? '') ?></td>
          <td class="p-2"><span class="px-2 py-0.5 rounded text-xs <?= $badge ?> status-cell"><?= esc($r['status'] ?? '') ?></span></td>
          <td class="p-2"><?= esc(($r['channel'] ?? '') ?: ($r['platform'] ?? '')) ?></td>
          <td class="p-2"><?= !empty($r['client_id']) ? (int)$r['client_id'] : '—' ?></td>
          <td class="p-2"><?= !empty($r['order_id']) ? (int)$r['order_id'] : '—' ?></td>
          <td class="p-2"><?= esc(short($r['subject'] ?? '')) ?></td>
          <td class="p-2"><?= esc(short(($r['body_text'] ?? '') ?: strip_tags((string)($r['body_html'] ?? '')))) ?></td>
          <td class="p-2"><?= esc(short($r['platform_thread_id'] ?? '')) ?></td>
          <td class="p-2">
            <div class="flex flex-wrap gap-2">
              <button class="px-2 py-1 border rounded text-xs" onclick="previewMsg(<?= (int)($r['id'] ?? 0) ?>)">Podgląd</button>
              <?php if (($r['direction'] ?? '')==='out' && ($r['status'] ?? '')==='queued'): ?>
                <button class="px-2 py-1 bg-black text-white rounded text-xs" onclick="sendNow(<?= (int)($r['id'] ?? 0) ?>)">Wyślij teraz</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php
    $pages = max(1, (int)ceil($total / $q_limit));
    if ($pages > 1):
  ?>
  <div class="flex items-center gap-2 mt-3">
    <?php for ($p=1;$p<=$pages;$p++):
      $qs = $_GET; $qs['page']=$p; $url = 'show.php?'.http_build_query($qs); ?>
      <a href="<?= esc($url) ?>" class="px-3 py-1 rounded border <?= $p==$q_page?'bg-black text-white':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Modal i JS -->
<div id="cw-modal" class="fixed inset-0 bg-black/50 hidden items-center justify-center">
  <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full p-4">
    <div class="flex justify-between items-center mb-2">
      <h2 class="text-lg font-semibold">Podgląd wiadomości</h2>
      <button onclick="hideModal()" class="text-gray-500">✕</button>
    </div>
    <div id="cw-modal-body" class="prose prose-sm max-w-none"></div>
  </div>
</div>

<script>
function previewMsg(id){
  fetch('show.php?id='+id, {credentials:'same-origin'})
    .then(r => r.text())
    .then(html => {
      document.getElementById('cw-modal-body').innerHTML = html;
      const m = document.getElementById('cw-modal');
      m.classList.remove('hidden'); m.classList.add('flex');
    })
    .catch(()=>alert('Nie udało się pobrać podglądu'));
}
function hideModal(){
  const m = document.getElementById('cw-modal');
  m.classList.add('hidden'); m.classList.remove('flex');
}
function sendNow(id){
  const row = document.getElementById('row-'+id);
  if (!row) return;
  const btns = row.querySelectorAll('button'); btns.forEach(b=>b.disabled=true);
  fetch('try_send.php?id='+id, {credentials:'same-origin'})
    .then(r=>r.json())
    .then(j=>{
      if (j && j.ok){
        fetch('status.php?id='+id, {credentials:'same-origin'})
          .then(r=>r.json())
          .then(s=>{
            const cell = row.querySelector('.status-cell');
            if (s && s.status){ cell.textContent = s.status; }
          });
      } else {
        alert('Nie wysłano: '+(j && j.why ? j.why : 'unknown'));
      }
    })
    .catch(()=>alert('Błąd żądania'))
    .finally(()=>btns.forEach(b=>b.disabled=false));
}
</script>

<?php require_once $BASE . '/layout/layout_footer.php'; ?>
