<?php
// admin/settings/owner/index.php — Olaj.pl V4 (owner profile panel, PRG-safe, UX++)
declare(strict_types=1);

/* ───────────────────────────── BOOT (no output) ───────────────────────────── */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

$user = $_SESSION['user'] ?? [];
$role = (string)($user['role'] ?? '');
if (!in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403);
    echo "<div style='max-width:640px;margin:2rem auto;padding:1rem;border:1px solid #f99;background:#fee;color:#a00'>
            Brak dostępu (wymagany admin/superadmin).
          </div>";
    exit;
}

$ownerId = (int)($user['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(400);
    echo "<div style='max-width:640px;margin:2rem auto;padding:1rem;border:1px solid #fd6;background:#fff3cd;color:#664d03'>
            Brak owner_id w sesji.
          </div>";
    exit;
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

/* Kolumny owners — auto-detekcja */
$stCols = $pdo->query("SHOW COLUMNS FROM owners");
$cols = $stCols ? $stCols->fetchAll(PDO::FETCH_COLUMN) : [];
$has  = fn(string $c) => in_array($c, $cols, true);

/* Edytowalne pola: zgodne z Twoją tabelą + przyszłościowe (pojawią się po ALTER) */
$editableFields = array_values(array_filter([
    $has('name') ? 'name' : null,
    $has('brand') ? 'brand' : null,
    $has('email') ? 'email' : null,
    $has('subscription_cached_json') ? 'subscription_cached_json' : null,
    $has('phone') ? 'phone' : null,
    $has('address') ? 'address' : null,
    $has('domain') ? 'domain' : null,
    $has('logo_url') ? 'logo_url' : null,
]));

/* Presety subskrypcji (używane w UI i/lub serwerowo) */
$subscriptionPresets = [
    'basic' => [
        'plan' => 'basic',
        'features' => ['shop', 'orders'],
        'limits' => ['orders_per_month' => 1000, 'live_sessions' => 0],
    ],
    'pro' => [
        'plan' => 'pro',
        'features' => ['shop', 'orders', 'live', 'cw'],
        'limits' => ['orders_per_month' => 5000, 'live_sessions' => 30],
    ],
    'ultra' => [
        'plan' => 'ultra',
        'features' => ['shop', 'orders', 'live', 'cw', 'ai'],
        'limits' => ['orders_per_month' => 20000, 'live_sessions' => 200],
    ],
];

/* ───────────────────────────── HANDLE POST (PRG) ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        http_response_code(400);
        echo "Błąd CSRF.";
        exit;
    }

    $action = $_POST['_action'] ?? 'save';

    // A) Danger: czyść cache subskrypcji (NULL)
    if ($action === 'clear_subscription_cache' && $has('subscription_cached_json')) {
        $sql = "UPDATE owners SET subscription_cached_json = NULL"
             . ($has('updated_at') ? ", updated_at = NOW()" : "")
             . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $ownerId]);
        $_SESSION['flash_msg'] = "🧹 Wyczyszczono cache subskrypcji.";
        header("Location: index.php");
        exit;
    }

    // B) Apply preset (serwerowo) — brak zagnieżdżonych form!
    if ($action === 'apply_preset' && $has('subscription_cached_json')) {
        $presetKey = (string)($_POST['preset'] ?? '');
        $preset = $subscriptionPresets[$presetKey] ?? null;
        if ($preset) {
            $json = json_encode($preset, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $sql = "UPDATE owners SET subscription_cached_json = :json"
                 . ($has('updated_at') ? ", updated_at = NOW()" : "")
                 . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['json' => $json, 'id' => $ownerId]);
            $_SESSION['flash_msg'] = "✅ Zastosowano preset „{$presetKey}”.";
        } else {
            $_SESSION['flash_msg'] = "❌ Nieznany preset.";
        }
        header("Location: index.php");
        exit;
    }

    // C) Standardowy zapis pól
    $updateMap = [];
    $params = ['id' => $ownerId];

    foreach ($editableFields as $col) {
        $val = $_POST[$col] ?? '';
        $val = is_string($val) ? trim($val) : '';

        // JSON walidacja
        if ($col === 'subscription_cached_json' && $val !== '') {
            json_decode($val, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $_SESSION['flash_msg'] = "❌ Błąd JSON: " . json_last_error_msg();
                header("Location: index.php");
                exit;
            }
        }

        // E-mail walidacja
        if ($col === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg'] = "❌ Nieprawidłowy e-mail.";
            header("Location: index.php");
            exit;
        }

        // Logo URL walidacja (jeśli kiedyś dodasz kolumnę)
        if ($col === 'logo_url' && $val !== '' && !preg_match('~^https?://~i', $val)) {
            $_SESSION['flash_msg'] = "❌ logo_url musi być pełnym adresem (http/https).";
            header("Location: index.php");
            exit;
        }

        $updateMap[] = "$col = :$col";
        $params[$col] = ($val === '') ? null : $val;
    }

    if (!empty($updateMap)) {
        $sql = "UPDATE owners SET " . implode(', ', $updateMap)
             . ($has('updated_at') ? ", updated_at = NOW()" : "")
             . " WHERE id = :id";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
        $_SESSION['flash_msg'] = "✅ Zapisano dane właściciela.";
    } else {
        $_SESSION['flash_msg'] = "ℹ️ Brak edytowalnych kolumn.";
    }

    header("Location: index.php");
    exit;
}

/* ───────────────────────────── RENDER (after PRG) ───────────────────────────── */

require_once __DIR__ . '/../../../layout/layout_header.php';

/* Flash */
$flash = null;
if (!empty($_SESSION['flash_msg'])) {
    $flash = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

/* Dane ownera */
$stmt = $pdo->prepare("SELECT * FROM owners WHERE id = ? LIMIT 1");
$stmt->execute([$ownerId]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* Meta pól (etykieta, typ, placeholder, ikonka) */
$meta = [
    'name' => [
        'label' => 'Nazwa firmy',
        'type'  => 'text',
        'placeholder' => 'np. Borowik Solutions sp. z o.o.',
        'icon' => '🏢',
    ],
    'brand' => [
        'label' => 'Marka (brand)',
        'type'  => 'text',
        'placeholder' => 'np. Olaj.pl',
        'icon' => '🏷️',
    ],
    'email' => [
        'label' => 'E-mail',
        'type'  => 'email',
        'placeholder' => 'np. kontakt@olaj.pl',
        'icon' => '✉️',
    ],
    'subscription_cached_json' => [
        'label' => 'Subskrypcja (cache JSON)',
        'type'  => 'textarea',
        'placeholder' => '{ "plan":"pro","features":["live","cw","stock"],"limits":{"orders_per_month":5000} }',
        'hint' => 'Musi być poprawny JSON (CHECK json_valid).',
        'icon' => '💼',
    ],
    'phone' => [
        'label' => 'Telefon',
        'type'  => 'text',
        'placeholder' => '+48 600 100 200',
        'icon' => '📞',
    ],
    'address' => [
        'label' => 'Adres',
        'type'  => 'textarea',
        'placeholder' => "Ulica 1\n00-000 Miasto",
        'icon' => '📮',
    ],
    'domain' => [
        'label' => 'Domena sklepu',
        'type'  => 'text',
        'placeholder' => 'olaj.pl',
        'icon' => '🌐',
    ],
    'logo_url' => [
        'label' => 'Logo (URL)',
        'type'  => 'text',
        'placeholder' => 'https://…/logo.png',
        'icon' => '🖼️',
    ],
];

/* Timestamps / audit */
$createdAt = $owner['created_at'] ?? null;
$updatedAt = $owner['updated_at'] ?? null;
?>
<div class="max-w-5xl mx-auto pb-24">
  <!-- Breadcrumbs -->
  <nav class="text-xs text-gray-500 mb-3">
    <a href="/admin/settings/index.php" class="hover:underline">⚙️ Ustawienia</a>
    <span class="mx-1">/</span>
    <span>Właściciel</span>
  </nav>

  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">🏪 Właściciel — profil</h1>
    <div class="text-xs text-gray-500">
      <?php if ($createdAt): ?>Utworzono: <span class="font-mono"><?= htmlspecialchars($createdAt) ?></span><?php endif; ?>
      <?php if ($updatedAt): ?> • Ostatnia zmiana: <span class="font-mono"><?= htmlspecialchars($updatedAt) ?></span><?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded bg-green-50 border border-green-200 text-green-700">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <!-- Główny formularz -->
  <form id="ownerForm" method="post">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="_action" value="save">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Kolumna 1-2: formularz -->
      <div class="lg:col-span-2 space-y-4">
        <?php foreach ($editableFields as $col): $def = $meta[$col] ?? ['label'=>$col,'type'=>'text','placeholder'=>'','icon'=>'▫️']; ?>
          <?php if (($def['type'] ?? 'text') === 'textarea'): ?>
            <div class="rounded-xl border p-4">
              <label class="block font-semibold mb-1">
                <span class="mr-1"><?= $def['icon'] ?? '' ?></span><?= htmlspecialchars($def['label']) ?>
              </label>
              <textarea name="<?= htmlspecialchars($col) ?>" rows="<?= $col === 'subscription_cached_json' ? 10 : 5 ?>"
                        class="w-full border px-3 py-2 rounded font-mono text-sm"
                        placeholder="<?= htmlspecialchars($def['placeholder']) ?>"><?= htmlspecialchars((string)($owner[$col] ?? '')) ?></textarea>
              <?php if (!empty($def['hint'])): ?>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($def['hint']) ?></p>
              <?php endif; ?>

              <?php if ($col === 'subscription_cached_json'): ?>
              <!-- Presety + akcje dla JSON (bez zagnieżdżonych form) -->
              <div class="flex flex-wrap items-center gap-2 mt-3">
                <button type="button" data-beautify
                        class="text-xs px-3 py-1 rounded border hover:bg-gray-50">Upiększ JSON</button>
                <button type="button" data-minify
                        class="text-xs px-3 py-1 rounded border hover:bg-gray-50">Zminimalizuj JSON</button>
                <button type="button" data-validate
                        class="text-xs px-3 py-1 rounded border hover:bg-gray-50">Sprawdź JSON</button>
                <span class="text-[11px] text-gray-400 ml-2">Znaki: <span id="jsonCount">0</span></span>
              </div>
              <div class="flex flex-wrap items-center gap-2 mt-2">
                <span class="text-[11px] text-gray-500 mr-2">Presety (lokalnie):</span>
                <button type="button" class="text-xs px-2 py-1 rounded border hover:bg-gray-50" data-preset="basic">basic</button>
                <button type="button" class="text-xs px-2 py-1 rounded border hover:bg-gray-50" data-preset="pro">pro</button>
                <button type="button" class="text-xs px-2 py-1 rounded border hover:bg-gray-50" data-preset="ultra">ultra</button>
                <span class="text-[11px] text-gray-500 ml-2">/ lub serwerowo:</span>
                <button type="button" id="applyPresetPro"
                        class="text-xs px-2 py-1 rounded border hover:bg-gray-50">Zastosuj preset na serwerze (pro)</button>
              </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="rounded-xl border p-4">
              <label class="block font-semibold mb-1">
                <span class="mr-1"><?= $def['icon'] ?? '' ?></span><?= htmlspecialchars($def['label']) ?>
              </label>
              <input type="<?= htmlspecialchars($def['type'] ?? 'text') ?>" name="<?= htmlspecialchars($col) ?>"
                     value="<?= htmlspecialchars((string)($owner[$col] ?? '')) ?>"
                     placeholder="<?= htmlspecialchars($def['placeholder']) ?>"
                     class="w-full border px-3 py-2 rounded">
              <?php if ($col === 'logo_url' && !empty($owner['logo_url'])): ?>
                <div class="mt-2 flex items-center gap-3">
                  <img src="<?= htmlspecialchars((string)$owner['logo_url']) ?>" alt="Logo" class="h-12 rounded">
                  <a href="<?= htmlspecialchars((string)$owner['logo_url']) ?>" target="_blank" class="text-xs text-blue-600 hover:underline">Otwórz</a>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Kolumna 3: karta podglądu / info -->
      <aside class="space-y-4">
        <div class="rounded-xl border p-4">
          <div class="flex items-center gap-2 mb-2">
            <span>🧾</span><h3 class="font-semibold">Podsumowanie</h3>
          </div>
          <ul class="text-sm text-gray-700 space-y-1">
            <li><span class="text-gray-500">ID:</span> <span class="font-mono"><?= (int)$ownerId ?></span></li>
            <li><span class="text-gray-500">Nazwa:</span> <?= htmlspecialchars((string)($owner['name'] ?? '—')) ?></li>
            <?php if ($has('brand')): ?>
              <li><span class="text-gray-500">Brand:</span> <?= htmlspecialchars((string)($owner['brand'] ?? '—')) ?></li>
            <?php endif; ?>
            <?php if ($has('email')): ?>
              <li><span class="text-gray-500">E-mail:</span> <?= htmlspecialchars((string)($owner['email'] ?? '—')) ?></li>
            <?php endif; ?>
            <?php if ($has('domain')): ?>
              <li><span class="text-gray-500">Domena:</span> <?= htmlspecialchars((string)($owner['domain'] ?? '—')) ?></li>
            <?php endif; ?>
            <?php if (!empty($createdAt)): ?>
              <li><span class="text-gray-500">Utworzono:</span> <span class="font-mono"><?= htmlspecialchars($createdAt) ?></span></li>
            <?php endif; ?>
            <?php if (!empty($updatedAt)): ?>
              <li><span class="text-gray-500">Zmieniono:</span> <span class="font-mono"><?= htmlspecialchars($updatedAt) ?></span></li>
            <?php endif; ?>
          </ul>
        </div>

        <?php if ($has('logo_url')): ?>
        <div class="rounded-xl border p-4">
          <div class="flex items-center gap-2 mb-2">
            <span>🖼️</span><h3 class="font-semibold">Logo — podgląd</h3>
          </div>
          <?php if (!empty($owner['logo_url'])): ?>
            <img src="<?= htmlspecialchars((string)$owner['logo_url']) ?>" alt="Logo" class="w-full max-h-40 object-contain rounded">
          <?php else: ?>
            <p class="text-sm text-gray-500">Brak logo. Uzupełnij pole „Logo (URL)”.</p>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($has('subscription_cached_json')): ?>
        <div class="rounded-xl border p-4">
          <div class="flex items-center gap-2 mb-2">
            <span>🧹</span><h3 class="font-semibold">Danger zone</h3>
          </div>
          <p class="text-sm text-gray-600">Czyści cache subskrypcji (ustawia <code>NULL</code>).</p>
          <form method="post" action="index.php" onsubmit="return confirm('Na pewno wyczyścić cache subskrypcji?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="_action" value="clear_subscription_cache">
            <button class="mt-2 px-3 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50 text-sm" type="submit">
              Wyczyść cache subskrypcji
            </button>
          </form>
        </div>
        <?php endif; ?>

      </aside>
    </div>

    <!-- Sticky akcje -->
    <div class="fixed bottom-4 left-0 right-0 pointer-events-none">
      <div class="max-w-5xl mx-auto flex justify-end px-3">
        <div class="pointer-events-auto bg-white/90 backdrop-blur rounded-xl border shadow p-2">
          <button form="ownerForm" type="submit"
                  class="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            💾 Zapisz zmiany
          </button>
        </div>
      </div>
    </div>
  </form>

  <!-- Ukryty formularz DO PRESETU (poza głównym; brak zagnieżdżenia) -->
  <form id="presetFormHidden" method="post" action="index.php" style="display:none">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="_action" value="apply_preset">
    <input type="hidden" name="preset" id="presetNameHidden" value="">
  </form>

  <!-- Git tip -->
  <div class="mt-10 text-xs text-gray-400">
    💡 Zrobiony push do gita?
    <code>git add . && git commit -m "settings/owner: final UX panel, PRG, no nested forms, presets via hidden form" && git push</code>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>

<!-- Lightweight helpers (bez frameworków) -->
<script>
// ======== TOPBAR NOTIFICATIONS: hardened ========
(() => {
  if (window.__TOPBAR_INIT__) return; // idempotent
  window.__TOPBAR_INIT__ = true;

  // 1) Global JS error trap (żeby nie wisiało w ciszy)
  (function attachGlobalErrorOverlay(){
    const mc = document.getElementById('marquee-content');
    window.addEventListener('error', (e) => {
      try {
        if (mc) mc.textContent = '⚠️ Błąd JS: ' + (e?.message || 'unknown');
      } catch(_) {}
      console.error('[topbar] JS error:', e);
    });
    window.addEventListener('unhandledrejection', (e) => {
      try {
        if (mc) mc.textContent = '⚠️ Błąd obietnicy: ' + (e?.reason?.message || 'unknown');
      } catch(_) {}
      console.error('[topbar] Unhandled promise rejection:', e);
    });
  })();

  // 2) Dark mode init + icons
  if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
  if (window.lucide && lucide.createIcons) lucide.createIcons();

  // 3) Dropdown user (bez zmian)
  const dd = document.getElementById('dropdown');
  const btn = document.getElementById('userMenuBtn');
  if (dd && btn) {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = dd.style.display === 'block';
      dd.style.display = open ? 'none' : 'block';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
    document.addEventListener('click', () => {
      dd.style.display = 'none';
      btn.setAttribute('aria-expanded', 'false');
    });
  }

  // 4) Sidebar hamburger (bez zmian)
  const hamburger = document.getElementById('hamburger');
  if (hamburger) {
    function syncAria() {
      const expanded = document.body.classList.contains('sidebar-open') || !document.body.classList.contains('sidebar-collapsed');
      hamburger.setAttribute('aria-expanded', String(expanded));
    }
    hamburger.addEventListener('click', (e) => {
      e.preventDefault();
      window.dispatchEvent(new CustomEvent('toggle-sidebar'));
      syncAria();
    });
    window.addEventListener('toggle-sidebar', syncAria);
    window.addEventListener('keydown', (e) => { if (e.key === 'Escape') setTimeout(syncAria, 0); });
    syncAria();
  }

  // 5) Notifications (timeout + watchdog + fallback)
  const marquee = document.getElementById('marquee-content');
  const badge   = document.getElementById('bell-badge');
  const bell    = document.getElementById('bell');

  const NOTIF_DEBUG = localStorage.getItem('notifDebug') === '1';
  const notifCandidates = [
    '/admin/api/notifications.php?format=objects',
    '/api/notifications.php?format=objects'
  ].map(u => NOTIF_DEBUG ? (u + (u.includes('?') ? '&' : '?') + 'debug=1') : u);

  const countsCandidates = [
    '/admin/api/notifications_counts.php',
    '/api/notifications_counts.php'
  ].map(u => NOTIF_DEBUG ? (u + (u.includes('?') ? '&' : '?') + 'debug=1') : u);

  let refreshTimer = null;

  function fetchWithTimeout(url, ms) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), ms);
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: ctrl.signal })
      .finally(() => clearTimeout(t));
  }

  async function fetchFirstOk(urls, ms) {
    let lastErr;
    for (const url of urls) {
      try {
        const res = await fetchWithTimeout(url, ms);
        if (!res.ok) { lastErr = new Error('HTTP ' + res.status + ' @ ' + url); continue; }
        const text = await res.text(); // defensywny parse
        try { return JSON.parse(text); }
        catch (e) { lastErr = new Error('JSON parse fail @ ' + url + ': ' + e); continue; }
      } catch (e) { lastErr = e; continue; }
    }
    throw lastErr || new Error('No endpoints responded');
  }

  function renderMarquee(items) {
    if (!marquee) return;
    marquee.innerHTML = '';
    if (!Array.isArray(items) || !items.length) {
      marquee.textContent = '✅ Brak nowych powiadomień.';
      return;
    }
    for (const it of items) {
      let icon = '🔔', text = '';
      if (it && typeof it === 'object') { icon = it.icon || '🔔'; text = String(it.text || ''); }
      else { text = String(it || ''); }
      const pill = document.createElement('span');
      pill.className = 'marq-pill';
      const ico = document.createElement('span'); ico.className = 'ico'; ico.textContent = icon;
      const txt = document.createElement('span'); txt.textContent = text;
      pill.appendChild(ico); pill.appendChild(txt);
      marquee.appendChild(pill);
    }
  }

  function renderCounts(m) {
    if (!badge || !bell) return;
    const queue = (m?.cw_queue | 0), errs = (m?.cw_errors | 0), logErrs = (m?.logs_errors_48h | 0);
    const badgeVal = queue + errs;
    badge.style.display = badgeVal > 0 ? 'inline-block' : 'none';
    if (badgeVal > 0) badge.textContent = String(badgeVal);
    bell.style.color = (errs > 0 || logErrs > 0) ? 'crimson' : '';
  }

  async function refresh() {
    if (marquee) marquee.textContent = '⏳ Ładowanie powiadomień...';
    const watchdog = setTimeout(() => {
      if (marquee) marquee.textContent = '⚠️ Błąd ładowania powiadomień (timeout).';
      if (badge)   badge.style.display = 'none';
    }, 10000);

    try {
      // Notifications
      const notifsRaw = await fetchFirstOk(notifCandidates, 8000);
      const items = Array.isArray(notifsRaw) ? notifsRaw : (notifsRaw?.notifications || []);
      renderMarquee(items);

      // Counts (opcjonalnie)
      try {
        const countsRaw = await fetchFirstOk(countsCandidates, 5000);
        if (countsRaw?.ok) renderCounts(countsRaw.metrics);
      } catch (e2) {
        if (NOTIF_DEBUG) console.warn('[notifications_counts] fail:', e2);
      }
    } catch (e) {
      if (NOTIF_DEBUG) console.error('[notifications] fail:', e);
      if (marquee) marquee.textContent = '⚠️ Błąd ładowania powiadomień.';
      if (badge)   badge.style.display = 'none';
    } finally {
      clearTimeout(watchdog);
    }
  }

  // Start + auto-refresh co 30s (z abortem poprzedniego)
  try { refresh(); } catch(e) { console.error(e); }
  clearInterval(refreshTimer);
  refreshTimer = setInterval(() => { try { refresh(); } catch(e) { console.error(e); } }, 30000);
})();
</script>

