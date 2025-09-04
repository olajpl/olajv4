<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/theme.php';

// Fallback getShopSettings
if (!function_exists('getShopSettings')) {
    function getShopSettings(PDO $pdo, int $ownerId): array
    {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='owner_settings' LIMIT 1");
        $st->execute();
        if (!$st->fetchColumn()) return [];
        $q = $pdo->prepare("SELECT `key`,`value`,`type` FROM owner_settings WHERE owner_id=:oid");
        $q->execute([':oid' => $ownerId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $v = $r['value'];
            switch ($r['type']) {
                case 'int':
                    $v = (int)$v;
                    break;
                case 'float':
                    $v = (float)$v;
                    break;
                case 'bool':
                    $v = in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
                    break;
                case 'json':
                    $d = json_decode((string)$v, true);
                    if (json_last_error() === JSON_ERROR_NONE) $v = $d;
                    break;
            }
            $out[$r['key']] = $v;
        }
        return $out;
    }
}

// CSRF + klient
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$client = function_exists('autoLoginFromCookie') ? (autoLoginFromCookie() ?: []) : [];
$isLoggedIn = !empty($_SESSION['client_id']);
$clientId   = (int)($_SESSION['client_id'] ?? 0);
$owner_id   = (int)($client['owner_id'] ?? 1);

// Ustawienia + motyw
$settings = getShopSettings($owner_id);
$theme    = resolveTheme($settings);

// Dane SSR (produkty, mini-koszyk, free-ship, live)
require __DIR__ . '/templates/partials/_data_boot.php';

// Render
$PAGE = [
    'title'       => $settings['shop_title'] ?? 'Sklep Olaj.pl',
    'description' => $settings['shop_description'] ?? 'Olaj.pl – sklep',
    'csrf'        => $csrf,
    'theme'       => $theme,
    'settings'    => $settings,
];

include __DIR__ . '/templates/layout/header.php';
include __DIR__ . '/templates/partials/ticker.php';
include __DIR__ . '/templates/partials/welcome.php';
include __DIR__ . '/templates/partials/product_grid.php';
include __DIR__ . '/templates/partials/minicart.php';
include __DIR__ . '/templates/partials/product_modal.php';
include __DIR__ . '/templates/partials/live_overlay.php';
include __DIR__ . '/templates/layout/footer.php';
