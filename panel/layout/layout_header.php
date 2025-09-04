<?php
// layout/layout_header.php — Olaj.pl V4
declare(strict_types=1);

define('PANEL', true);
if (!defined('APP_START')) define('APP_START', microtime(true));

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$pageTitle = $pageTitle ?? 'Olaj.pl Panel';

/**
 * Sprawdza, czy tabela istnieje – bezpiecznie (nie wywala PDOException).
 */
function tableExists(PDO $pdo, string $table): bool
{
  try {
    $pdo->query("DESCRIBE `$table`");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Pobiera ustawienie właściciela po kluczu, jeśli istnieje tabela owner_settings.
 */
function getOwnerSetting(PDO $pdo, int $ownerId, string $key): ?string
{
  if ($ownerId <= 0) return null;
  if (!tableExists($pdo, 'owner_settings')) return null;
  try {
    $st = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id = :oid AND `key` = :k LIMIT 1");
    $st->execute(['oid' => $ownerId, 'k' => $key]);
    $val = $st->fetchColumn();
    return ($val !== false && $val !== null) ? (string)$val : null;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Pobierz przyjazną nazwę połączonej strony (FB) – zgodnie z V4.
 */
function getConnectedPageName(PDO $pdo, int $ownerId): ?string
{
  if ($ownerId <= 0) return null;

  foreach (['facebook_page_name', 'facebook.page_name'] as $key) {
    $v = getOwnerSetting($pdo, $ownerId, $key);
    if ($v) return $v;
  }

  if (tableExists($pdo, 'facebook_tokens')) {
    try {
      $st = $pdo->prepare("SELECT page_name FROM facebook_tokens WHERE owner_id = :oid ORDER BY id DESC LIMIT 1");
      $st->execute(['oid' => $ownerId]);
      $v = $st->fetchColumn();
      if ($v) return (string)$v;
    } catch (Throwable $e) {
      // ign
    }
  }

  return null;
}

$pageName = getConnectedPageName($pdo, $owner_id);
if ($pageName) $pageTitle .= ' — ' . $pageName;

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function isActive(string $href, string $currentPath): bool
{
  return strncmp($currentPath, $href, strlen($href)) === 0;
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8" />
  <meta http-equiv="x-ua-compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.bootstrap5.min.css">
  <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/alpinejs" defer></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <style>
    :root {
      --header-h: 60px;
      --footer-h: 56px;
    }

    html,
    body {
      height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
    }

    body {
      height: 100vh;
      overflow: hidden;
      background: #f3f4f6;
    }

    nav.sidebar {
      padding-top: var(--header-h);
      height: calc(100vh - var(--footer-h));
      overflow-y: auto;
    }

    main#content {
      padding-top: var(--header-h);
      padding-bottom: var(--footer-h);
      height: calc(100vh - var(--header-h) - var(--footer-h));
      overflow-y: auto;
    }

    .status-dot {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .status-ok {
      background: #16a34a;
    }

    .status-fail {
      background: #dc2626;
    }
  </style>
</head>

<body>

  <?php include __DIR__ . '/top_panel.php'; ?>

  <div class="flex min-h-0">
    <!-- Sidebar -->
    <nav class="sidebar w-[220px] bg-gray-900 text-white px-4 flex-shrink-0 hidden md:block">
      <div class="flex items-center justify-between mb-6 pt-2">
        <h3 class="font-bold">Olaj.pl</h3>
        <?php if ($pageName): ?>
          <span class="text-[11px] text-gray-300 whitespace-nowrap" title="Połączona strona">
            🌐 <?= htmlspecialchars($pageName) ?>
          </span>
        <?php endif; ?>
      </div>

      <?php
      $items = require __DIR__ . '/../includes/sidebar_items.php';
      include __DIR__ . '/../includes/sidebar_renderer.php';
      ?>

      <a href="/auth/logout.php" class="block py-2 px-3 rounded-md text-red-400 mt-2 mb-6 hover:bg-gray-800 transition">
        🚪 Wyloguj
      </a>
    </nav>

    <!-- 📜 tylko to ma scroll -->
    <main id="content" class="flex-1 px-6 pb-12">

      <script>
        document.addEventListener("DOMContentLoaded", function() {
          lucide.createIcons();
        });
      </script>