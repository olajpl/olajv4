<?php
// admin/settings/shop/index.php — Olaj V4
// Panel ustawień sklepu (shop_settings) per owner
// - CSRF, walidacja, INSERT ON DUPLICATE KEY UPDATE
// - Logowanie: logg('info'|'error', 'settings.shop', ...)
// - UX: Tailwind, live preview koloru/motywu

declare(strict_types=1);
session_start();

// --- Autoryzacja (standard panelu) ---
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    header("Location: /auth/login.php?redirect=$redirect");
    exit;
}
require_once __DIR__ . '/../../../includes/login_check.php';

// --- Includes wspólne ---
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/log.php'; // udostępnia logg() / wlog()

$ownerId   = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId    = (int)($_SESSION['user']['id'] ?? 0);
$userEmail = (string)($_SESSION['user']['email'] ?? '');

// --- CSRF helper ---
if (!isset($_SESSION['csrf_shop_settings'])) {
    $_SESSION['csrf_shop_settings'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_shop_settings'];

// --- Domyślne wartości (fallback) ---
$defaults = [
    'status'           => 'active',
    'shop_name'        => 'Olaj.pl',
    'base_url'         => '',
    'currency'         => 'PLN',
    'theme'            => 'default',
    'theme_color'      => '#111827',
    'logo_url'         => '',
    'favicon_url'      => '',
    'meta_title'       => 'Olaj.pl — sklep',
    'meta_description' => 'Znane marki w dobrej cenie.',
    'shipping_note'    => '',
    'payment_note'     => '',
    'social_json'      => '',
    'flags'            => 0,
    'metadata'         => '',
];

// --- Odczyt aktualnych ustawień ---
$current = $defaults;
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tblExists = $pdo->query("SHOW TABLES LIKE 'shop_settings'")->fetchColumn();
    if (!$tblExists) {
        // Jeśli ktoś jeszcze nie zdeployował tabeli — nie zabijamy widoku
        wlog('admin.settings.shop: missing shop_settings table, using defaults');
    } else {
        $st = $pdo->prepare("SELECT * FROM shop_settings WHERE owner_id = :oid LIMIT 1");
        $st->execute([':oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $current = array_replace($defaults, $row);
            // JSONy do edycji jako tekst
            if (is_array($current['social_json'])) $current['social_json'] = json_encode($current['social_json'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            if (is_array($current['metadata']))    $current['metadata']    = json_encode($current['metadata'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        }
    }
} catch (Throwable $e) {
    logg('error', 'settings.shop', 'load.exception', [
        'owner_id' => $ownerId,
        'error'    => $e->getMessage(),
    ]);
}

$errors = [];
$notice = null;

// --- Zapis (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals($csrf, $token)) {
        $errors[] = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
    }

    // Pobranie pól
    $payload = [
        'status'           => trim((string)($_POST['status'] ?? 'active')),
        'shop_name'        => trim((string)($_POST['shop_name'] ?? '')),
        'base_url'         => rtrim(trim((string)($_POST['base_url'] ?? '')), '/'),
        'currency'         => strtoupper(trim((string)($_POST['currency'] ?? 'PLN'))),
        'theme'            => trim((string)($_POST['theme'] ?? 'default')),
        'theme_color'      => trim((string)($_POST['theme_color'] ?? '#111827')),
        'logo_url'         => trim((string)($_POST['logo_url'] ?? '')),
        'favicon_url'      => trim((string)($_POST['favicon_url'] ?? '')),
        'meta_title'       => trim((string)($_POST['meta_title'] ?? '')),
        'meta_description' => trim((string)($_POST['meta_description'] ?? '')),
        'shipping_note'    => trim((string)($_POST['shipping_note'] ?? '')),
        'payment_note'     => trim((string)($_POST['payment_note'] ?? '')),
        'social_json'      => (string)($_POST['social_json'] ?? ''),
        'flags'            => (int)($_POST['flags'] ?? 0),
        'metadata'         => (string)($_POST['metadata'] ?? ''),
    ];

    // Walidacja prosta
    if ($payload['shop_name'] === '')        $errors[] = 'Nazwa sklepu jest wymagana.';
    if ($payload['currency'] === '' || strlen($payload['currency']) !== 3) $errors[] = 'Waluta musi mieć 3 litery (np. PLN, EUR).';
    if ($payload['theme_color'] && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $payload['theme_color'])) {
        $errors[] = 'Kolor motywu musi być w formacie HEX (np. #111827).';
    }
    if ($payload['base_url'] && !preg_match('#^https?://#i', $payload['base_url'])) {
        $errors[] = 'Base URL musi zaczynać się od http:// lub https://';
    }
    if (!in_array($payload['status'], ['active','inactive'], true)) {
        $errors[] = 'Nieprawidłowy status.';
    }

    // Walidacja JSON
    $socialArr = null;
    $metaArr   = null;
    if (trim($payload['social_json']) !== '') {
        $socialArr = json_decode($payload['social_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Pole Social JSON zawiera błędny JSON.';
        }
    }
    if (trim($payload['metadata']) !== '') {
        $metaArr = json_decode($payload['metadata'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Pole Metadata zawiera błędny JSON.';
        }
    }

    // Próba zapisu
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Seed jeśli brak wiersza dla ownera
            $pdo->prepare("
                INSERT INTO shop_settings (owner_id, status, shop_name, base_url, currency, theme, theme_color)
                SELECT :oid, 'active', 'Olaj.pl', NULL, 'PLN', 'default', '#111827'
                WHERE NOT EXISTS (SELECT 1 FROM shop_settings WHERE owner_id = :oid2)
            ")->execute([':oid' => $ownerId, ':oid2' => $ownerId]);

            // Upsert aktualizacji
            $sql = "
                INSERT INTO shop_settings
                    (owner_id, status, shop_name, base_url, currency, theme, theme_color,
                     logo_url, favicon_url, meta_title, meta_description,
                     shipping_note, payment_note, social_json, flags, metadata)
                VALUES
                    (:owner_id, :status, :shop_name, :base_url, :currency, :theme, :theme_color,
                     :logo_url, :favicon_url, :meta_title, :meta_description,
                     :shipping_note, :payment_note, :social_json, :flags, :metadata)
                ON DUPLICATE KEY UPDATE
                    status           = VALUES(status),
                    shop_name        = VALUES(shop_name),
                    base_url         = VALUES(base_url),
                    currency         = VALUES(currency),
                    theme            = VALUES(theme),
                    theme_color      = VALUES(theme_color),
                    logo_url         = VALUES(logo_url),
                    favicon_url      = VALUES(favicon_url),
                    meta_title       = VALUES(meta_title),
                    meta_description = VALUES(meta_description),
                    shipping_note    = VALUES(shipping_note),
                    payment_note     = VALUES(payment_note),
                    social_json      = VALUES(social_json),
                    flags            = VALUES(flags),
                    metadata         = VALUES(metadata)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':owner_id'         => $ownerId,
                ':status'           => $payload['status'],
                ':shop_name'        => $payload['shop_name'],
                ':base_url'         => ($payload['base_url'] ?: null),
                ':currency'         => $payload['currency'],
                ':theme'            => $payload['theme'],
                ':theme_color'      => $payload['theme_color'],
                ':logo_url'         => ($payload['logo_url'] ?: null),
                ':favicon_url'      => ($payload['favicon_url'] ?: null),
                ':meta_title'       => ($payload['meta_title'] ?: null),
                ':meta_description' => ($payload['meta_description'] ?: null),
                ':shipping_note'    => ($payload['shipping_note'] ?: null),
                ':payment_note'     => ($payload['payment_note'] ?: null),
                ':social_json'      => ($socialArr ? json_encode($socialArr, JSON_UNESCAPED_UNICODE) : null),
                ':flags'            => $payload['flags'],
                ':metadata'         => ($metaArr ? json_encode($metaArr, JSON_UNESCAPED_UNICODE) : null),
            ]);

            $pdo->commit();

            logg('info', 'settings.shop', 'save.success', [
                'owner_id' => $ownerId,
                'user_id'  => $userId,
                'payload'  => [
                    'status'      => $payload['status'],
                    'shop_name'   => $payload['shop_name'],
                    'base_url'    => $payload['base_url'],
                    'currency'    => $payload['currency'],
                    'theme'       => $payload['theme'],
                    'theme_color' => $payload['theme_color'],
                ],
            ]);

            $notice = 'Ustawienia zapisane poprawnie. ✔';
            // Odśwież podgląd
            $current = array_replace($current, $payload);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Błąd zapisu ustawień: ' . $e->getMessage();
            logg('error', 'settings.shop', 'save.exception', [
                'owner_id' => $ownerId,
                'user_id'  => $userId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}

// --- HTML ---
?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Ustawienia sklepu — Olaj V4</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Tailwind (zakładam, że globalnie w panelu już masz) -->
  <link rel="stylesheet" href="/assets/tailwind.css">
  <style>
    /* drobny polish */
    .input { @apply w-full rounded-xl border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500; }
    .label { @apply block text-sm font-medium text-gray-700 mb-1; }
    .card  { @apply bg-white rounded-2xl shadow p-4 md:p-6; }
    .btn   { @apply inline-flex items-center gap-2 rounded-xl px-4 py-2 font-semibold; }
    .btn-primary { @apply bg-indigo-600 text-white hover:bg-indigo-700; }
    .btn-ghost   { @apply text-gray-600 hover:text-gray-900; }
    .grid-col { @apply grid grid-cols-1 md:grid-cols-2 gap-4; }
    .pill { @apply inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">
  <?php include __DIR__ . '/../../../layout/layout_header.php'; ?>

  <main class="max-w-6xl mx-auto mt-24 mb-16 px-3 md:px-6">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl md:text-3xl font-bold">Ustawienia sklepu</h1>
      <a href="/admin/index.php" class="btn btn-ghost">← Powrót</a>
    </div>

    <?php if ($errors): ?>
      <div class="card border border-red-200 mb-4">
        <div class="text-red-700 font-semibold mb-2">🙈 Wystąpiły błędy:</div>
        <ul class="list-disc ml-5 text-red-700">
          <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($notice): ?>
      <div class="card border border-emerald-200 mb-4 text-emerald-800">
        <?= htmlspecialchars($notice) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

      <div class="card">
        <div class="grid-col">
          <div>
            <label class="label">Status</label>
            <select name="status" class="input">
              <option value="active"   <?= ($current['status'] ?? '') === 'active' ? 'selected' : '' ?>>active</option>
              <option value="inactive" <?= ($current['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>inactive</option>
            </select>
          </div>
          <div>
            <label class="label">Waluta (ISO 4217)</label>
            <input name="currency" class="input" maxlength="3" value="<?= htmlspecialchars((string)$current['currency']) ?>">
          </div>

          <div>
            <label class="label">Nazwa sklepu</label>
            <input name="shop_name" class="input" value="<?= htmlspecialchars((string)$current['shop_name']) ?>">
          </div>
          <div>
            <label class="label">Base URL</label>
            <input name="base_url" class="input" placeholder="https://olaj.pl" value="<?= htmlspecialchars((string)($current['base_url'] ?? '')) ?>">
          </div>

          <div>
            <label class="label">Motyw (theme)</label>
            <input name="theme" class="input" value="<?= htmlspecialchars((string)$current['theme']) ?>">
          </div>
          <div>
            <label class="label">Kolor motywu (HEX)</label>
            <div class="flex items-center gap-3">
              <input id="themeColor" name="theme_color" class="input" value="<?= htmlspecialchars((string)$current['theme_color']) ?>">
              <span id="colorSwatch" class="inline-block w-10 h-10 rounded-xl border" style="background: <?= htmlspecialchars((string)$current['theme_color']) ?>;"></span>
            </div>
          </div>

          <div>
            <label class="label">Logo URL</label>
            <input name="logo_url" class="input" placeholder="https://..." value="<?= htmlspecialchars((string)($current['logo_url'] ?? '')) ?>">
          </div>
          <div>
            <label class="label">Favicon URL</label>
            <input name="favicon_url" class="input" placeholder="https://..." value="<?= htmlspecialchars((string)($current['favicon_url'] ?? '')) ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="grid-col">
          <div>
            <label class="label">Meta title</label>
            <input name="meta_title" class="input" value="<?= htmlspecialchars((string)($current['meta_title'] ?? '')) ?>">
          </div>
          <div>
            <label class="label">Meta description</label>
            <input name="meta_description" class="input" value="<?= htmlspecialchars((string)($current['meta_description'] ?? '')) ?>">
          </div>
        </div>
        <div class="grid-col mt-4">
          <div>
            <label class="label">Notka o wysyłce (shipping_note)</label>
            <textarea name="shipping_note" class="input" rows="4"><?= htmlspecialchars((string)($current['shipping_note'] ?? '')) ?></textarea>
          </div>
          <div>
            <label class="label">Notka o płatnościach (payment_note)</label>
            <textarea name="payment_note" class="input" rows="4"><?= htmlspecialchars((string)($current['payment_note'] ?? '')) ?></textarea>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="grid-col">
          <div>
            <label class="label">Social JSON</label>
            <textarea id="socialJson" name="social_json" class="input font-mono text-sm" rows="8" placeholder='{"instagram":"@twojprofil","facebook":"twoj_fanpage"}'><?= htmlspecialchars((string)($current['social_json'] ?? '')) ?></textarea>
            <div id="socialJsonStatus" class="mt-1 text-xs"></div>
          </div>
          <div>
            <label class="label">Metadata (JSON)</label>
            <textarea id="metaJson" name="metadata" class="input font-mono text-sm" rows="8" placeholder='{"feature_flags":["x","y"],"gtm":"GTM-XXXX"}'><?= htmlspecialchars((string)($current['metadata'] ?? '')) ?></textarea>
            <div id="metaJsonStatus" class="mt-1 text-xs"></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="pill bg-gray-100 text-gray-800">owner_id: <?= (int)$ownerId ?></span>
            <span class="pill bg-gray-100 text-gray-800">user: <?= htmlspecialchars($userEmail) ?></span>
          </div>
          <div class="flex items-center gap-3">
            <a href="/admin/settings/shop/index.php" class="btn btn-ghost">Reset</a>
            <button type="submit" class="btn btn-primary">Zapisz ustawienia</button>
          </div>
        </div>
      </div>
    </form>

    <div class="mt-8 text-sm text-gray-500">
      Tip: linki social w <code>social_json</code> możesz podać jako <code>{"instagram":"@olaj.pl","facebook":"olajpl"}</code>.
    </div>
  </main>
  <?php include __DIR__ . '/../../../layout/layout_footer.php'; ?>
  <script>
    // Live swatch koloru
    const colorInput = document.getElementById('themeColor');
    const swatch = document.getElementById('colorSwatch');
    if (colorInput && swatch) {
      colorInput.addEventListener('input', () => {
        swatch.style.background = colorInput.value || '#111827';
      });
    }

    // Lekka walidacja JSON (client-side)
    function jsonStatus(el, statusEl) {
      try {
        const v = el.value.trim();
        if (!v) {
          statusEl.textContent = '— pusty (OK)';
          statusEl.className = 'mt-1 text-xs text-gray-500';
          return;
        }
        JSON.parse(v);
        statusEl.textContent = '✓ poprawny JSON';
        statusEl.className = 'mt-1 text-xs text-emerald-700';
      } catch (e) {
        statusEl.textContent = '✗ błędny JSON';
        statusEl.className = 'mt-1 text-xs text-red-700';
      }
    }
    const sj = document.getElementById('socialJson');
    const sjS = document.getElementById('socialJsonStatus');
    const mj = document.getElementById('metaJson');
    const mjS = document.getElementById('metaJsonStatus');
    if (sj && sjS) { sj.addEventListener('input', ()=>jsonStatus(sj, sjS)); jsonStatus(sj, sjS); }
    if (mj && mjS) { mj.addEventListener('input', ()=>jsonStatus(mj, mjS)); jsonStatus(mj, mjS); }
  </script>
</body>
</html>
