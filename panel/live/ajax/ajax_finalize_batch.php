<?php
// admin/live/ajax/ajax_finalize_batch.php
require_once __DIR__ . '/__live_boot.php';

require_once APP_ROOT . '/engine/orders/ClientEngine.php';
require_once APP_ROOT . '/engine/orders/ProductEngine.php';
require_once APP_ROOT . '/engine/orders/PaymentEngine.php';
require_once APP_ROOT . '/engine/live/LiveEngine.php';

use Engine\Orders\ClientEngine;
use Engine\Orders\ProductEngine;
use Engine\Orders\PaymentEngine;
use Engine\Live\LiveEngine;

try {
  $owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
  $live_id    = (int)($_POST['live_id'] ?? 0);
  $operatorId = (int)($_SESSION['user']['id'] ?? 0);
  if ($owner_id<=0 || $live_id<=0) json_out(['success'=>false, 'error'=>'Brak kontekstu.']);

  $clients  = new ClientEngine($pdo);
  $products = new ProductEngine($pdo);
  $payments = new PaymentEngine($pdo);
  $live     = new LiveEngine($pdo, $clients, $products, $payments);

  // Zakładamy, że LiveEngine ma finalizeBatch zgodnie z ustaleniami (all-or-nothing)
  $res = $live->finalizeBatch($owner_id, $live_id, $operatorId);
  if (!($res['ok'] ?? false)) {
    json_out(['success'=>false, 'error'=>$res['error'] ?? 'Finalizacja nie powiodła się']);
  }
  json_out(['success'=>true]);
} catch (Throwable $e) {
  json_out(['success'=>false, 'error'=>$e->getMessage()], 200);
}
