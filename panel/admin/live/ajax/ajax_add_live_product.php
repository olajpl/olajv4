<?php
declare(strict_types=1);
// admin/live/ajax/ajax_add_live_product.php
define('APP_ROOT', realpath(__DIR__ . '/../../..')); // 3 katalogi w górę od /admin/live/ajax/
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/db.php';

require_once __DIR__ . '/__live_boot.php';

require_once APP_ROOT . '/engine/Orders/ClientEngine.php';
require_once APP_ROOT . '/engine/Orders/ProductEngine.php';
require_once APP_ROOT . '/engine/Orders/PaymentEngine.php';
require_once APP_ROOT . '/engine/Live/LiveEngine.php';
if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit; // ważne: niech endpoint kończy się natychmiast po wysyłce JSON
    }
}
use Engine\Orders\ClientEngine;
use Engine\Orders\ProductEngine;
use Engine\Orders\PaymentEngine;
use Engine\Live\LiveEngine;

try {
  $owner_id   = (int)($_POST['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
  $live_id    = (int)($_POST['live_id'] ?? 0);
  $operatorId = (int)($_POST['operator_user_id'] ?? ($_SESSION['user']['id'] ?? 0));
  $is_custom  = (int)($_POST['is_custom'] ?? 0);

  $client_ids = $_POST['client_ids'] ?? [];
  $qtys       = $_POST['qty'] ?? [];
  if (!is_array($client_ids)) $client_ids = [$client_ids];
  if (!is_array($qtys))       $qtys = [$qtys];

  if ($owner_id<=0 || $live_id<=0) throw new InvalidArgumentException('Brak kontekstu (owner/live).');
  if (empty($client_ids)) throw new InvalidArgumentException('Wybierz klientów.');

  $payload = [
    'owner_id'=>$owner_id,
    'live_id'=>$live_id,
    'operator_user_id'=>$operatorId,
  ];

  if ($is_custom === 1) {
    $name  = trim((string)($_POST['name'] ?? ''));
    $sku   = trim((string)($_POST['sku'] ?? ''));
    $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
    $vat   = (float)($_POST['vat_rate'] ?? 23);

    if ($name==='' || $price <= 0) throw new InvalidArgumentException('Podaj nazwę i cenę dla custom.');

    $payload += [
      'is_custom'=>1, 'source_type'=>'custom',
      'name'=>$name, 'sku'=>$sku, 'price'=>$price, 'vat_rate'=>$vat
    ];
  } else {
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($product_id<=0) throw new InvalidArgumentException('Wybierz produkt katalogowy lub zaznacz custom.');
    $payload += ['is_custom'=>0, 'source_type'=>'catalog', 'product_id'=>$product_id];
  }

  $clients  = new ClientEngine($pdo);
  $products = new ProductEngine($pdo);
  $payments = new PaymentEngine($pdo);
  $live     = new LiveEngine($pdo, $clients, $products, $payments);

  $added = [];
  foreach ($client_ids as $i=>$cid) {
    $cid = (int)$cid; if ($cid<=0) continue;
    $qty = (int)($qtys[$i] ?? 1); if ($qty<=0) $qty = 1;

    $res = $live->addItem($payload + ['client_id'=>$cid, 'qty'=>$qty]);
    $added[] = $res;
  }

  if (!$added) throw new RuntimeException('Nic nie dodano.');
  json_out(['success'=>true, 'added'=>$added]);
} catch (Throwable $e) {
  json_out(['success'=>false, 'error'=>$e->getMessage()], 200);
}
