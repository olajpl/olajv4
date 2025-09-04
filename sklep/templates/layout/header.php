<?php
$cdn  = rtrim($PAGE['settings']['cdn_url'] ?? 'https://panel.olaj.pl', '/');
$cssVars = injectCssVars($PAGE['settings']);
?>
<!doctype html>
<html lang="pl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?= htmlspecialchars($PAGE['title']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($PAGE['description']) ?>" />
    <meta name="csrf" content="<?= htmlspecialchars($PAGE['csrf']) ?>">
    <meta name="theme-color" content="<?= htmlspecialchars($PAGE['settings']['theme_color'] ?? '#ec4899') ?>">

    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <style>
        <?= $cssVars ?>
    </style>
    <link rel="stylesheet" href="<?= htmlspecialchars(themeUrl($PAGE['theme'])) ?>">

    <?php if (!empty($PAGE['settings']['custom_css'])): ?>
        <style>
            <?= $PAGE['settings']['custom_css'] ?>
        </style>
    <?php endif; ?>
</head>

<body class="bg-gray-100 text-gray-800" style="font-family: var(--font-family);">
    <?php include __DIR__ . '/../partials/topbar.php'; ?>