<?php

declare(strict_types=1);

function resolveTheme(array $settings): string
{
    if (!empty($_GET['theme'])) {
        $t = preg_replace('~[^a-z0-9_]+~i', '', (string)$_GET['theme']);
        if ($t) {
            setcookie('shop_theme', $t, time() + 60 * 60 * 24 * 180, '/', '', isset($_SERVER['HTTPS']), true);
            return strtolower($t);
        }
    }
    if (!empty($_COOKIE['shop_theme'])) {
        return preg_replace('~[^a-z0-9_]+~i', '', (string)$_COOKIE['shop_theme']);
    }
    if (!empty($settings['shop_theme'])) {
        return preg_replace('~[^a-z0-9_]+~i', '', (string)$settings['shop_theme']);
    }
    return 'default';
}

function themeUrl(string $theme): string
{
    return '/assets/css/themes/' . $theme . '.css';
}

function injectCssVars(array $settings): string
{
    $themeColor = $settings['theme_color'] ?? '#ec4899';
    $font       = $settings['font_family'] ?? 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    $accent     = $settings['accent_color'] ?? $themeColor;

    return ":root{
      --theme-color: {$themeColor};
      --accent-color: {$accent};
      --font-family: {$font};
    }";
}
