<?php

use CentralMessaging\TemplateRenderer;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/centralMessaging/TemplateRenderer.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$event    = $_POST['event_key'] ?? '';
$channel  = $_POST['channel'] ?? 'sms';
$data     = $_POST['data'] ?? [];

$st = $pdo->prepare("SELECT * FROM cw_templates WHERE owner_id=? AND event_key=? AND channel=? AND active=1 LIMIT 1");
$st->execute([$owner_id, $event, $channel]);
$tpl = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$render = $tpl ? TemplateRenderer::render($tpl, $data) : null;
header('Content-Type: application/json');
echo json_encode(['ok' => (bool)$render, 'rendered' => $render]);
