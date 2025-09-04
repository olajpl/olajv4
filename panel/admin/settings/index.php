<?php
// admin/settings/index.php — Olaj.pl V4 (Settings Home / Tiles) — superadmin-safe
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../layout/layout_header.php';

// Opcjonalnie odśwież opcache (dev-friendly)
if (function_exists('opcache_reset')) {
  @opcache_reset();
}

$user = $_SESSION['user'] ?? [];
$role = (string)($user['role'] ?? '');
$isSuperadmin = (
  in_array($role, ['superadmin', 'suadmin', 'root', 'admin_super'], true)
  || (int)($user['is_superadmin'] ?? 0) === 1
);

// CSRF (pod przyszłe akcje POST na tej stronie, np. seedy)
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Helper kafelka (pozwala renderować disabled, gdy brak uprawnień)
function tile(string $href, string $emoji, string $title, string $desc, bool $enabled = true): string
{
  $base = 'block rounded-xl border p-4 transition ' . ($enabled ? 'hover:shadow-md' : 'opacity-60 cursor-not-allowed');
  $aTagOpen = $enabled ? '<a href="' . htmlspecialchars($href) . '" class="' . $base . '">' : '<div class="' . $base . '">';
  $aTagClose = $enabled ? '</a>' : '</div>';
  return $aTagOpen
    . '<div class="flex items-center gap-3">'
    .   '<span class="text-2xl">' . htmlspecialchars($emoji) . '</span>'
    .   '<div>'
    .     '<div class="font-semibold">' . htmlspecialchars($title) . ($enabled ? '' : ' <span class="text-xs">🔒</span>') . '</div>'
    .     '<div class="text-xs text-gray-500">' . htmlspecialchars($desc) . '</div>'
    .   '</div>'
    . '</div>'
    . $aTagClose;
}
?>
<div class="max-w-6xl mx-auto py-4">
  <div class="mb-6">
    <h1 class="text-2xl font-bold">⚙️ Ustawienia systemu</h1>
    <p class="text-gray-600 text-sm">Panel główny konfiguracji Olaj.pl V4 — czytelne jak wół na lodzie, dojebane jak Pudzian.</p>
  </div>

  <!-- Sklep & Właściciel -->
  <section class="mb-8">
    <h2 class="text-lg font-semibold mb-3">Sklep &amp; właściciel</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?= tile('/admin/settings/owner/index.php',   '🏪', 'Dane właściciela', 'Nazwy, logotypy, domeny, brand.'); ?>
      <?= tile('/admin/settings/shop/index.php',    '🛒', 'Sklep (frontend)', 'Wygląd, motyw, UX, banery.'); ?>
      <?= tile('/admin/settings/checkout/index.php', '🧾', 'Checkout V2',      'Tokeny, grupy, flow, reguły.'); ?>
    </div>
  </section>

  <!-- Płatności & Dostawa -->
  <section class="mb-8">
    <h2 class="text-lg font-semibold mb-3">Płatności &amp; Dostawa</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?= tile('/admin/settings/payments.php', '💳', 'Metody płatności', 'Online, przelew, COD — konfiguracja.'); ?>
      <?= tile('/admin/settings/shipping.php', '📦', 'Wysyłka', 'Kurierzy, paczkomaty, progi wagowe.'); ?>
      <?= tile('/admin/settings/labels.php',  '🏷️', 'Etykiety & tracking', 'Integracje, szablony, statusy.'); ?>
    </div>
  </section>

  <!-- Komunikacja & AI -->
  <section class="mb-8">
    <h2 class="text-lg font-semibold mb-3">Komunikacja &amp; AI</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?= tile('/admin/cw/settings.php', '📨', 'CW — wysyłacz', 'Kolejka, retry, szablony, eventy.'); ?>
      <?= tile('/admin/settings/ai.php', '🧠', 'AI / LLM', 'Provider, model, klucze, timeouty.'); ?>
      <?= tile('/admin/logs/index.php', '🗂️', 'Logi systemowe', 'olaj_v4_logger / LogEngine.'); ?>
    </div>
  </section>

  <!-- Superadmin: Subskrypcje (SaaS) + Edytor ENUM + System -->
  <section class="mb-8">
    <h2 class="text-lg font-semibold mb-3">Superadmin</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

      <!-- Subskrypcje: kafel + skróty -->
      <div class="rounded-xl border p-4 <?= $isSuperadmin ? 'hover:shadow-md' : 'opacity-60' ?> transition">
        <div class="flex items-center gap-3 mb-2">
          <span class="text-2xl">💼</span>
          <div>
            <div class="font-semibold">Subskrypcje (SaaS) <?= $isSuperadmin ? '' : '<span class="text-xs">🔒</span>' ?></div>
            <div class="text-xs text-gray-500">Plany, funkcje, limity — enum_values.</div>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-2">
          <a class="text-xs px-2 py-1 rounded border hover:bg-gray-50 <?= $isSuperadmin ? '' : 'pointer-events-none' ?>"
            href="<?= $isSuperadmin ? '/admin/settings/Enums/index.php?set=subscription_plan' : '#' ?>">Plany</a>
          <a class="text-xs px-2 py-1 rounded border hover:bg-gray-50 <?= $isSuperadmin ? '' : 'pointer-events-none' ?>"
            href="<?= $isSuperadmin ? '/admin/settings/Enums/index.php?set=subscription_feature' : '#' ?>">Funkcje</a>
          <a class="text-xs px-2 py-1 rounded border hover:bg-gray-50 <?= $isSuperadmin ? '' : 'pointer-events-none' ?>"
            href="<?= $isSuperadmin ? '/admin/settings/Enums/index.php?set=subscription_limit' : '#' ?>">Limity</a>
        </div>
        <form method="post" action="<?= $isSuperadmin ? '/admin/settings/subscription_seed.php' : '#' ?>" class="mt-3">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit"
            class="text-xs px-3 py-1 rounded <?= $isSuperadmin ? 'bg-gray-900 text-white hover:opacity-90' : 'bg-gray-200 text-gray-500 cursor-not-allowed' ?>">
            Zseeduj domyślne wartości
          </button>
          <p class="mt-1 text-[11px] text-gray-500">Utworzy standardowe plany (basic/pro/ultra), podstawowe funkcje i limity.</p>
        </form>
      </div>

      <!-- Edytor ENUM -->
      <?= tile('/admin/settings/Enums/index.php', '🧩', 'Edytor ENUM', 'enum_values — CRUD (tylko superadmin).', $isSuperadmin); ?>

      <!-- System & Bezpieczeństwo (opcjonalne) -->
      <?= tile('/admin/settings/system.php', '🛡️', 'System & bezpieczeństwo', 'Dostępy, role, maintenance, wersje.', $isSuperadmin); ?>
    </div>
  </section>

  <div class="mt-8 text-xs text-gray-400">
    💡 Zrobiony push do gita?
    <code>git add . && git commit -m "settings: expose SaaS+ENUM tiles; superadmin detection harden" && git push</code>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>