<?php
// shop/_partials/order_helpers.php
// Helpery wizualne do wyświetlania zamówień w frontendzie sklepu

use Engine\Orders\ViewRenderer;

require_once __DIR__ . '/../../engine/orders/ViewRenderer.php';

if (!function_exists('renderPayChip')) {
    function renderPayChip(string $status): string
    {
        return ViewRenderer::renderPayChip($status);
    }
}

if (!function_exists('renderWeightBadge')) {
    function renderWeightBadge(float $kg): string
    {
        return ViewRenderer::renderWeightBadge($kg);
    }
}

if (!function_exists('renderStatusBadge')) {
    function renderStatusBadge(string $status): string
    {
        return ViewRenderer::renderStatusBadge($status);
    }
}
