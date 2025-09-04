<?php
// admin/clients/view.php
// Widok klienta (Olaj V4): dane klienta + tagi + KPI + ostatnie zamówienia
// + zarządzanie powiązaniami kont (master_client_id) + AJAX client_search.
// Odporne SQL (qty/unit_price vs qty/unit_price), brak payment_status w orders.

// ---------- BOOT ----------
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

// ---------- Logger (centralny) z bezpiecznym fallbackiem ----------
$__logger_ready = false;
try {
  if (file_exists(__DIR__ . '/../../includes/log.php')) {
    require_once __DIR__ . '/../../includes/log.php'; // rejestruje logg()/wlog()
    $__logger_ready = function_exists('logg');
  }
  if (!$__logger_ready && file_exists(__DIR__ . '/../../engine/Log/LogEngine.php')) {
    require_once __DIR__ . '/../../engine/Log/LogEngine.php';
    $__logger_ready = true;
  }
} catch (Throwable $e) { /* ignore */
}

if (!function_exists('logg')) {
  function logg(string $level, string $channel, string $message, array $context = [], array $extra = []): void
  {
    error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'context', 'extra'), JSON_UNESCAPED_UNICODE));
  }
}
if (!function_exists('wlog')) {
  function wlog(string $message, array $ctx = []): void
  {
    logg('info', 'default', $message, $ctx);
  }
}

// ---------- PARAMS & GUARDS ----------
$owner_id  = (int)($_SESSION['user']['owner_id'] ?? 0);
$client_id = (int)($_GET['id'] ?? 0);

if ($owner_id <= 0 || $client_id <= 0) {
  http_response_code(400);
  exit('Brak kontekstu właściciela lub klienta.');
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// ---------- AJAX: wyszukiwarka klientów (do attach/detach UI) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'client_search') {
  header('Content-Type: application/json; charset=UTF-8');
  $q = trim((string)($_GET['q'] ?? ''));
  $limit = 20;

  $where = "c.owner_id = :oid AND c.id <> :self";
  if ($q !== '') {
    $where .= " AND (
      c.name  LIKE :q1 OR
      c.email LIKE :q2 OR
      c.phone LIKE :q3 OR
      c.token LIKE :q4
    )";
  }

  $sql = "
    SELECT c.id, c.name, c.email, c.phone, c.token, c.master_client_id
    FROM clients c
    WHERE $where
    ORDER BY c.name IS NULL, c.name ASC, c.id DESC
    LIMIT $limit
  ";

  $st = $pdo->prepare($sql);
  $st->bindValue(':oid',  $owner_id,  PDO::PARAM_INT);
  $st->bindValue(':self', $client_id, PDO::PARAM_INT);
  if ($q !== '') {
    $like = '%' . $q . '%';
    $st->bindValue(':q1', $like, PDO::PARAM_STR);
    $st->bindValue(':q2', $like, PDO::PARAM_STR);
    $st->bindValue(':q3', $like, PDO::PARAM_STR);
    $st->bindValue(':q4', $like, PDO::PARAM_STR);
  }
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode([
    'ok' => true,
    'results' => array_map(fn($r) => [
      'id'    => (int)$r['id'],
      'label' => trim(($r['name'] ?? '') !== '' ? $r['name'] : ('Klient ' . ($r['token'] ?? ''))),
      'email' => $r['email'] ?? '',
      'phone' => $r['phone'] ?? '',
      'token' => $r['token'] ?? '',
      'master_client_id' => isset($r['master_client_id']) ? (int)$r['master_client_id'] : null,
    ], $rows)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------- CSRF helper ----------
function csrf_ok(string $token): bool
{
  return hash_equals($_SESSION['csrf'] ?? '', $token);
}

// ---------- POST: attach/detach/set master/child ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
  $action = (string)$_POST['action'];
  $csrf   = (string)($_POST['csrf'] ?? '');
  if (!csrf_ok($csrf)) {
    http_response_code(403);
    exit('Błędny CSRF.');
  }

  // helper: czy klient należy do ownera
  $owns = function (PDO $pdo, int $id, int $owner_id): bool {
    $s = $pdo->prepare("SELECT 1 FROM clients WHERE id = :id AND owner_id = :oid");
    $s->execute([':id' => $id, ':oid' => $owner_id]);
    return (bool)$s->fetchColumn();
  };

  // helper: czy powstanie cykl master-child
  $createsCycle = function (PDO $pdo, int $potentialMasterId, int $childId): bool {
    if ($potentialMasterId === $childId) return true;
    $seen = [];
    $current = $potentialMasterId;
    while ($current) {
      if (isset($seen[$current])) return true;
      $seen[$current] = true;
      $st = $pdo->prepare("SELECT master_client_id FROM clients WHERE id = :id LIMIT 1");
      $st->execute([':id' => $current]);
      $current = (int)($st->fetchColumn() ?: 0);
      if ($current === $childId) return true;
    }
    return false;
  };

  try {
    if ($action === 'attach_child') {
      $child_id = (int)($_POST['child_id'] ?? 0);
      if ($child_id <= 0) throw new RuntimeException('Brak child_id');
      if (!$owns($pdo, $child_id, $owner_id)) throw new RuntimeException('Brak uprawnień do child');
      if ($child_id === $client_id) throw new RuntimeException('Nie można podpiąć konta do siebie');
      if ($createsCycle($pdo, $client_id, $child_id)) throw new RuntimeException('Operacja spowodowałaby pętlę master-child.');

      $st = $pdo->prepare("UPDATE clients SET master_client_id = :mid WHERE id = :cid AND owner_id = :oid");
      $st->execute([':mid' => $client_id, ':cid' => $child_id, ':oid' => $owner_id]);
      logg('info', 'clients.view', 'attach_child', ['client' => $client_id, 'child' => $child_id, 'owner' => $owner_id]);
      header("Location: view.php?id={$client_id}&msg=attached_child");
      exit;
    }

    if ($action === 'detach_child') {
      $child_id = (int)($_POST['child_id'] ?? 0);
      if ($child_id <= 0) throw new RuntimeException('Brak child_id');
      if (!$owns($pdo, $child_id, $owner_id)) throw new RuntimeException('Brak uprawnień do child');

      $st = $pdo->prepare("UPDATE clients SET master_client_id = NULL WHERE id = :cid AND owner_id = :oid AND master_client_id = :mid");
      $st->execute([':cid' => $child_id, ':oid' => $owner_id, ':mid' => $client_id]);
      logg('info', 'clients.view', 'detach_child', ['client' => $client_id, 'child' => $child_id, 'owner' => $owner_id]);
      header("Location: view.php?id={$client_id}&msg=detached_child");
      exit;
    }

    if ($action === 'set_master') {
      $master_id = (int)($_POST['master_id'] ?? 0);
      if ($master_id <= 0) throw new RuntimeException('Brak master_id');
      if (!$owns($pdo, $master_id, $owner_id)) throw new RuntimeException('Brak uprawnień do master');
      if ($master_id === $client_id) throw new RuntimeException('Nie można ustawić siebie jako mastera');
      if ($createsCycle($pdo, $master_id, $client_id)) throw new RuntimeException('Operacja spowodowałaby pętlę master-child.');

      $st = $pdo->prepare("UPDATE clients SET master_client_id = :mid WHERE id = :cid AND owner_id = :oid");
      $st->execute([':mid' => $master_id, ':cid' => $client_id, ':oid' => $owner_id]);
      logg('info', 'clients.view', 'set_master', ['client' => $client_id, 'master' => $master_id, 'owner' => $owner_id]);
      header("Location: view.php?id={$client_id}&msg=set_master_ok");
      exit;
    }

    if ($action === 'detach_master') {
      $st = $pdo->prepare("UPDATE clients SET master_client_id = NULL WHERE id = :cid AND owner_id = :oid");
      $st->execute([':cid' => $client_id, ':oid' => $owner_id]);
      logg('info', 'clients.view', 'detach_master', ['client' => $client_id, 'owner' => $owner_id]);
      header("Location: view.php?id={$client_id}&msg=detached_master");
      exit;
    }

    throw new RuntimeException('Nieznana akcja');
  } catch (Throwable $e) {
    logg('error', 'clients.view', 'master_link_error', ['err' => $e->getMessage(), 'client' => $client_id, 'owner' => $owner_id]);
    header("Location: view.php?id={$client_id}&msg=error");
    exit;
  }
}

// ---------- LOG: otwarcie ----------
logg('info', 'clients.view', 'open', ['client_id' => $client_id, 'owner_id' => $owner_id]);

// ---------- DANE KLIENTA ----------
$st = $pdo->prepare("
  SELECT c.id, c.owner_id, c.name, c.email, c.phone, c.token, c.last_seen, c.registered_at, c.updated_at, c.master_client_id
  FROM clients c
  WHERE c.id = :cid AND c.owner_id = :oid
  LIMIT 1
");
$st->execute([':cid' => $client_id, ':oid' => $owner_id]);
$client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$client) {
  http_response_code(404);
  exit('Nie znaleziono klienta.');
}

// ---------- MASTER (jeśli jest) ----------
$master = null;
if (!empty($client['master_client_id'])) {
  $st = $pdo->prepare("
    SELECT id, name, email, phone, token
    FROM clients
    WHERE id = :id AND owner_id = :oid
    LIMIT 1
  ");
  $st->execute([':id' => (int)$client['master_client_id'], ':oid' => $owner_id]);
  $master = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------- DZIECI ----------
$children = [];
try {
  $st = $pdo->prepare("
    SELECT id, name, email, phone, token
    FROM clients
    WHERE master_client_id = :mid AND owner_id = :oid
    ORDER BY name IS NULL, name ASC, id DESC
  ");
  $st->execute([':mid' => $client_id, ':oid' => $owner_id]);
  $children = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  logg('warning', 'clients.view', 'children_query_failed', [
    'client_id' => $client_id,
    'err' => $e->getMessage()
  ]);
  $children = [];
}

// ---------- TAGI ----------
$tags = [];
try {
  $st = $pdo->prepare("
    SELECT t.id, t.name, COALESCE(NULLIF(t.color,''),'#999999') AS color
    FROM client_tag_links l
    JOIN client_tags t ON t.id = l.tag_id
    WHERE l.client_id = :cid
    ORDER BY t.name
  ");
  $st->execute([':cid' => $client_id]);
  $tags = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  logg('warning', 'clients.view', 'tags_query_failed', ['client_id' => $client_id, 'err' => $e->getMessage()]);
  $tags = [];
}

// ---------- OSTATNIE ZAMÓWIENIA (bez o.payment_status; z paid_status z og) ----------
$st = $pdo->prepare("
  SELECT
    o.id,
    o.created_at,
    o.order_status,
    COALESCE(SUM(COALESCE(oi.qty, oi.qty, 0) * COALESCE(oi.unit_price,  0)), 0) AS total_amount,
    COALESCE(SUM(COALESCE(oi.qty, oi.qty, 0)), 0) AS total_items,
    MAX(
      CASE
        -- EN
        WHEN og.paid_status = 'paid'      THEN 4
        WHEN og.paid_status = 'overpaid'  THEN 3
        WHEN og.paid_status = 'partial'   THEN 2
        WHEN og.paid_status = 'unpaid'    THEN 1
        -- PL (na wszelki)
        WHEN og.paid_status = 'opłacona'  THEN 4
        WHEN og.paid_status = 'nadpłata'  THEN 3
        WHEN og.paid_status = 'częściowa' THEN 2
        WHEN og.paid_status = 'nieopłacona' THEN 1
        ELSE 0
      END
    ) AS paid_rank
  FROM orders o
  LEFT JOIN order_groups og ON og.order_id = o.id
  LEFT JOIN order_items  oi ON oi.order_group_id = og.id
  WHERE o.client_id = :cid AND o.owner_id = :oid
  GROUP BY o.id
  ORDER BY o.created_at DESC
  LIMIT 50
");
$st->execute([':cid' => $client_id, ':oid' => $owner_id]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---------- KPI ----------
$ordersCount = count($orders);
$sumTotal = 0.0;
$sumItems = 0;
$oplacCount = 0;
foreach ($orders as $o) {
  $sumTotal += (float)$o['total_amount'];
  $sumItems += (int)$o['total_items'];
  if ((int)$o['paid_rank'] >= 4) $oplacCount++;
}

$clientLabel = ($client['name'] ?? '') !== ''
  ? $client['name']
  : ('Klient ' . ($client['token'] ?? ''));

// ---------- UI helpers ----------
if (!function_exists('fmt_price')) {
  function fmt_price(float $amount, string $currency = 'zł'): string
  {
    return number_format($amount, 2, ',', ' ') . ' ' . $currency;
  }
}
if (!function_exists('fmt_dt')) {
  function fmt_dt(?string $dt): string
  {
    return $dt ? date('Y-m-d H:i', strtotime($dt)) : '—';
  }
}
$badgeOrder = function (string $status): array {
  $map = [
    'nowe'                 => ['Nowe', 'bg-gray-100 text-gray-800'],
    'oczekuje_na_dane'     => ['Oczekuje na dane', 'bg-stone-100 text-stone-800'],
    'oczekuje_na_płatność' => ['Czeka na płatność', 'bg-amber-100 text-amber-800'],
    'otwarta_paczka'       => ['Otwarta paczka', 'bg-amber-50 text-amber-700'],
    'gotowe_do_wysyłki'    => ['Do wysyłki', 'bg-blue-100 text-blue-800'],
    'w_realizacji'         => ['W realizacji', 'bg-indigo-100 text-indigo-800'],
    'wysłane'              => ['Wysłane', 'bg-indigo-100 text-indigo-800'],
    'zrealizowane'         => ['Zrealizowane', 'bg-emerald-100 text-emerald-800'],
    'zarchiwizowane'       => ['Zarchiwizowane', 'bg-stone-100 text-stone-700'],
    'anulowane'            => ['Anulowane', 'bg-stone-100 text-stone-700'],
  ];
  return $map[$status] ?? [$status ?: '—', 'bg-stone-100 text-stone-700'];
};
$paidByRank = function (int $rank): array {
  if ($rank >= 4) return ['Opłacona', 'bg-emerald-100 text-emerald-800'];
  if ($rank === 3) return ['Nadpłata', 'bg-blue-100 text-blue-800'];
  if ($rank === 2) return ['Częściowa', 'bg-amber-100 text-amber-800'];
  if ($rank === 1) return ['Nieopłacona', 'bg-stone-100 text-stone-800'];
  return ['—', 'bg-stone-100 text-stone-700'];
};

// ---------- RENDER ----------
require_once __DIR__ . '/../../layout/layout_header.php';
require_once __DIR__ . '/../../layout/top_panel.php';
?>
<style>
  .chip {
    border-radius: 9999px;
    padding: 2px 8px;
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  }

  .kpi-card {
    border-radius: 16px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .05);
  }

  .btn {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px 10px;
  }

  .btn:hover {
    background: #f9fafb;
  }

  .row-hover tr:hover {
    background: #fafafa;
  }

  .dropdown {
    position: relative;
  }

  .dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, .06);
    z-index: 20;
    max-height: 240px;
    overflow: auto;
  }

  .dropdown-item {
    padding: 8px 10px;
    cursor: pointer;
  }

  .dropdown-item:hover {
    background: #f3f4f6;
  }
</style>

<div class="p-4 space-y-4">
  <?php if (!empty($_GET['msg'])): ?>
    <?php $ok = in_array($_GET['msg'], ['attached_child', 'detached_child', 'set_master_ok', 'detached_master'], true); ?>
    <div class="px-3 py-2 rounded text-sm <?= $ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>">
      <?= htmlspecialchars($_GET['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div class="flex items-center gap-3">
      <a href="/admin/clients/index.php" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
        <span class="text-lg">←</span> Wróć
      </a>
      <h1 class="text-2xl font-bold">👤 <?= htmlspecialchars($clientLabel, ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="flex gap-2">
      <a href="/admin/messages/index.php?client_id=<?= (int)$client['id'] ?>" class="btn">📨 Historia wiadomości</a>
      <a href="/admin/orders/index.php?client_id=<?= (int)$client['id'] ?>" class="btn">🧾 Zamówienia klienta</a>
    </div>
  </div>

  <!-- Info + KPI -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- LEFT -->
    <div class="bg-white border rounded p-4 kpi-card lg:col-span-2">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
          <div class="text-xl font-semibold"><?= htmlspecialchars($client['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-stone-600"><?= htmlspecialchars($client['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
          <div class="text-stone-600"><?= htmlspecialchars($client['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="flex items-center gap-2">
          <span class="chip mono bg-stone-100 text-stone-700" id="client-token"><?= htmlspecialchars($client['token'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
          <button class="btn text-sm" id="btn-copy-token" title="Kopiuj token">📋 Kopiuj</button>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap gap-2 text-sm">
        <span class="px-2 py-1 rounded bg-stone-100 text-stone-700">Zarejestrowany: <?= fmt_dt($client['registered_at'] ?? null) ?></span>
        <span class="px-2 py-1 rounded bg-stone-100 text-stone-700">Ostatnio widziany: <?= fmt_dt($client['last_seen'] ?? null) ?></span>
        <span class="px-2 py-1 rounded bg-stone-100 text-stone-700">Aktualizacja: <?= fmt_dt($client['updated_at'] ?? null) ?></span>
      </div>

      <!-- Tagi -->
      <div class="mt-4">
        <div class="text-sm font-medium mb-2">Tagi klienta</div>
        <div class="flex flex-wrap gap-2">
          <?php if ($tags): ?>
            <?php foreach ($tags as $t): ?>
              <span class="chip" style="background: <?= htmlspecialchars($t['color'], ENT_QUOTES, 'UTF-8') ?>20; color: #1f2937; border:1px solid <?= htmlspecialchars($t['color'], ENT_QUOTES, 'UTF-8') ?>40;">
                <span class="w-2 h-2 rounded-full" style="background: <?= htmlspecialchars($t['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
              </span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="text-stone-500 text-sm">Brak tagów</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT KPI -->
    <div class="grid grid-cols-3 gap-3">
      <div class="bg-white border rounded p-4 kpi-card">
        <div class="text-xs text-stone-500">Zamówienia</div>
        <div class="text-2xl font-bold"><?= (int)$ordersCount ?></div>
      </div>
      <div class="bg-white border rounded p-4 kpi-card">
        <div class="text-xs text-stone-500">Łączna wartość</div>
        <div class="text-2xl font-bold"><?= fmt_price((float)$sumTotal) ?></div>
      </div>
      <div class="bg-white border rounded p-4 kpi-card">
        <div class="text-xs text-stone-500">Opłacone (pełne)</div>
        <div class="text-2xl font-bold"><?= (int)$oplacCount ?></div>
      </div>
    </div>
  </div>

  <!-- Powiązania kont -->
  <div class="bg-white border rounded p-4">
    <h2 class="text-lg font-semibold mb-3">🔗 Powiązania kont</h2>

    <!-- MASTER -->
    <div class="mb-4">
      <div class="text-sm font-medium mb-1">Master dla tego konta:</div>

      <?php if ($master): ?>
        <div class="flex flex-wrap items-center gap-2">
          <span class="chip bg-stone-100">
            #<?= (int)$master['id'] ?> — <?= htmlspecialchars($master['name'] ?: ('Klient ' . $master['token']), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($master['email'])): ?> • <?= htmlspecialchars($master['email']) ?><?php endif; ?>
              <?php if (!empty($master['phone'])): ?> • <?= htmlspecialchars($master['phone']) ?><?php endif; ?>
          </span>

          <form method="post" onsubmit="return confirm('Odłączyć od mastera?')" class="inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
            <input type="hidden" name="action" value="detach_master">
            <button class="btn text-sm">❌ Odłącz mastera</button>
          </form>
        </div>
      <?php else: ?>
        <div class="text-sm text-stone-600 mb-2">Brak — możesz ustawić mastera poniżej.</div>
      <?php endif; ?>

      <div class="mt-3">
        <form method="post" class="flex flex-wrap items-center gap-2" onsubmit="return !!document.getElementById('master_id').value;">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="action" value="set_master">
          <input type="hidden" name="master_id" id="master_id" value="">
          <div class="dropdown" style="min-width:280px;">
            <input type="text" id="master_search" class="border rounded px-2 py-1 w-full" placeholder="Szukaj klienta po nazwie/e-mailu/telefonie/tokenie…">
            <div id="master_results" class="dropdown-menu" style="display:none;"></div>
          </div>
          <button class="btn text-sm">➕ Ustaw/Zmień mastera</button>
        </form>
      </div>
    </div>

    <hr class="my-4">

    <!-- DZIECI -->
    <div>
      <div class="text-sm font-medium mb-2">Konta podpięte pod to konto (dzieci):</div>

      <form method="post" class="flex flex-wrap items-center gap-2 mb-3" onsubmit="return !!document.getElementById('child_id').value;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="attach_child">
        <input type="hidden" name="child_id" id="child_id" value="">
        <div class="dropdown" style="min-width:280px;">
          <input type="text" id="child_search" class="border rounded px-2 py-1 w-full" placeholder="Wyszukaj konto do podpięcia…">
          <div id="child_results" class="dropdown-menu" style="display:none;"></div>
        </div>
        <button class="btn text-sm">➕ Podepnij dziecko</button>
      </form>

      <?php if ($children): ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm row-hover">
            <thead class="bg-stone-50 text-stone-600">
              <tr>
                <th class="px-3 py-2 text-left">ID</th>
                <th class="px-3 py-2 text-left">Nazwa</th>
                <th class="px-3 py-2 text-left">Kontakt</th>
                <th class="px-3 py-2 text-center">Akcje</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($children as $ch): ?>
                <tr>
                  <td class="px-3 py-2">#<?= (int)$ch['id'] ?></td>
                  <td class="px-3 py-2">
                    <?= htmlspecialchars(($ch['name'] ?: ('Klient ' . $ch['token'])), ENT_QUOTES, 'UTF-8') ?>
                  </td>
                  <td class="px-3 py-2 text-stone-600">
                    <?php if (!empty($ch['email'])): ?><?= htmlspecialchars($ch['email']) ?><?php endif; ?>
                    <?php if (!empty($ch['phone'])): ?><?= !empty($ch['email']) ? ' • ' : '' ?><?= htmlspecialchars($ch['phone']) ?><?php endif; ?>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <form method="post" onsubmit="return confirm('Odłączyć to konto?')" class="inline">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
                      <input type="hidden" name="action" value="detach_child">
                      <input type="hidden" name="child_id" value="<?= (int)$ch['id'] ?>">
                      <button class="btn text-sm">❌ Odłącz</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-sm text-stone-600">Brak podpiętych kont.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Ostatnie zamówienia -->
  <div class="bg-white border rounded p-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold">Ostatnie zamówienia klienta</h2>
      <div class="text-sm text-stone-600">Limit: 50</div>
    </div>

    <?php if (!$orders): ?>
      <div class="text-stone-600 text-sm">Ten klient nie ma jeszcze zamówień.</div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm row-hover">
          <thead class="bg-stone-50 text-stone-600">
            <tr>
              <th class="px-3 py-2 text-left">ID</th>
              <th class="px-3 py-2 text-left">Status</th>
              <th class="px-3 py-2 text-left">Płatność</th>
              <th class="px-3 py-2 text-right">Pozycje</th>
              <th class="px-3 py-2 text-right">Suma</th>
              <th class="px-3 py-2 text-left">Utworzone</th>
              <th class="px-3 py-2 text-center">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <?php
              [$lbl, $cls]  = $badgeOrder((string)($o['order_status'] ?? ''));
              [$plb, $pcls] = $paidByRank((int)$o['paid_rank']);
              ?>
              <tr>
                <td class="px-3 py-2">
                  <a class="text-blue-600 hover:underline" href="/admin/orders/view.php?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a>
                </td>
                <td class="px-3 py-2"><span class="px-2 py-1 rounded text-xs <?= $cls ?>"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="px-3 py-2"><span class="px-2 py-1 rounded text-xs <?= $pcls ?>"><?= htmlspecialchars($plb, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="px-3 py-2 text-right"><?= (int)$o['total_items'] ?></td>
                <td class="px-3 py-2 text-right"><?= fmt_price((float)$o['total_amount']) ?></td>
                <td class="px-3 py-2"><?= fmt_dt($o['created_at'] ?? null) ?></td>
                <td class="px-3 py-2 text-center">
                  <a class="btn text-xs" href="/admin/orders/view.php?id=<?= (int)$o['id'] ?>">Szczegóły</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="bg-stone-50">
            <tr class="font-medium">
              <td class="px-3 py-2" colspan="3">Razem</td>
              <td class="px-3 py-2 text-right"><?= (int)$sumItems ?></td>
              <td class="px-3 py-2 text-right"><?= fmt_price((float)$sumTotal) ?></td>
              <td class="px-3 py-2" colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  // Kopiowanie tokenu
  (function() {
    const btn = document.getElementById('btn-copy-token');
    const el = document.getElementById('client-token');
    if (!btn || !el) return;
    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText((el.textContent || '').trim());
        btn.textContent = 'Skopiowano ✔';
        setTimeout(() => btn.textContent = '📋 Kopiuj', 900);
      } catch {
        alert('Nie udało się skopiować tokenu.');
      }
    });
  })();

  // Dropdown-search (AJAX -> ?ajax=client_search)
  function setupSearch(inputId, resultsId, hiddenId) {
    const inp = document.getElementById(inputId);
    const box = document.getElementById(resultsId);
    const hid = document.getElementById(hiddenId);
    if (!inp || !box || !hid) return;

    let t = null,
      lastQ = '';

    function hide() {
      box.style.display = 'none';
      box.innerHTML = '';
    }

    function show() {
      box.style.display = 'block';
    }

    inp.addEventListener('input', () => {
      const q = (inp.value || '').trim();
      hid.value = '';
      if (t) clearTimeout(t);
      if (q.length < 2) {
        hide();
        return;
      }
      t = setTimeout(async () => {
        if (q === lastQ) return;
        lastQ = q;
        try {
          const url = new URL(location.href);
          url.searchParams.set('ajax', 'client_search');
          url.searchParams.set('q', q);
          const res = await fetch(url.toString(), {
            cache: 'no-cache'
          });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const data = await res.json();
          box.innerHTML = '';
          if (data && data.ok && Array.isArray(data.results) && data.results.length) {
            data.results.forEach(r => {
              const item = document.createElement('div');
              item.className = 'dropdown-item';
              item.textContent = `#${r.id} — ${r.label}` + (r.email ? ` • ${r.email}` : '') + (r.phone ? ` • ${r.phone}` : '');
              item.addEventListener('click', () => {
                hid.value = r.id;
                inp.value = `#${r.id} — ${r.label}`;
                hide();
              });
              box.appendChild(item);
            });
            show();
          } else {
            hide();
          }
        } catch (e) {
          hide();
        }
      }, 250);
    });

    document.addEventListener('click', (e) => {
      if (!box.contains(e.target) && e.target !== inp) hide();
    });
  }

  setupSearch('master_search', 'master_results', 'master_id');
  setupSearch('child_search', 'child_results', 'child_id');
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>