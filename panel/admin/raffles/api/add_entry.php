<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../engine/Marketing/RaffleEngine.php';

use Engine\Marketing\RaffleEngine;

header('Content-Type: application/json; charset=utf-8');

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);
$engine  = new RaffleEngine($pdo, $ownerId);

$drawId = (int)($_POST['draw_id'] ?? 0);
$entry  = [
  'platform'          => $_POST['platform'] ?? 'manual',
  'platform_id'       => $_POST['platform_id'] ?? '',
  'display_name'      => $_POST['display_name'] ?? '',
  'weight'            => (int)($_POST['weight'] ?? 1),
  'source'            => $_POST['source'] ?? null,
  'added_by_admin_id' => (int)($_SESSION['user']['id'] ?? 0) ?: null,
];

echo json_encode($engine->addEntry($drawId, $entry));
