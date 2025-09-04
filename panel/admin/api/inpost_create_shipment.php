<?php

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../engine/Shipping/InPostClient.php';
require_once __DIR__ . '/../../engine/Shipping/InPostService.php';
require_once __DIR__ . '/../../engine/CentralMessaging/CwHelper.php';

use Engine\Shipping\InPostClient;
use Engine\Shipping\InPostService;
use Engine\CentralMessaging\CwHelper;

header('Content-Type: application/json; charset=utf-8');

try {
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $groupId = (int)($_POST['order_group_id'] ?? 0);
    $methodId = isset($_POST['shipping_method_id']) ? (int)$_POST['shipping_method_id'] : null;

    if ($orderId <= 0 || $groupId <= 0) throw new RuntimeException('order_id and order_group_id are required');

    $apiUrl = (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.api_url') ?? 'https://api-shipx-pl.easypack24.net');
    $token  = (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.api_token') ?? '');
    $orgId  = (int)   (CwHelper::getOwnerSetting($ownerId, 'inpost.organization_id') ?? 0);
    if ($token === '' || $orgId <= 0) throw new RuntimeException('Missing InPost credentials');

    $client  = new InPostClient($apiUrl, $token, $orgId);
    $service = new InPostService($pdo, $client, [
        'label_format'     => (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.label_format') ?? 'Pdf'),
        'label_type'       => (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.label_type')   ?? 'A6'),
        'public_label_base' => (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.public_label_base') ?? '/uploads/labels/inpost'),
        'fs_label_base'    => (string)(CwHelper::getOwnerSetting($ownerId, 'inpost.fs_label_base')     ?? (__DIR__ . '/../../uploads/labels/inpost')),
    ]);

    $out = $service->createShipmentForGroup($ownerId, $orderId, $groupId, $methodId);
    if (!($out['ok'] ?? false)) {
        http_response_code(500);
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
