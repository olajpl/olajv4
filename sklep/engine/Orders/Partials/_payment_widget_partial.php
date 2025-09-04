<?php

declare(strict_types=1);
/**
 * engine/orders/partials/_payment_widget_partial.php
 * Widżet płatności (V4)
 * Wymaga: $pdo (PDO), $order_id (int), $owner_id (int)
 */

use PDO;

require_once __DIR__ . '/../../../includes/log.php';

// Walidacja danych wejściowych
if (!isset($pdo, $order_id, $owner_id)) {
  throw new RuntimeException("_payment_widget_partial.php wymaga \$pdo, \$order_id, \$owner_id");
}
$order_id = (int)$order_id;
$owner_id = (int)$owner_id;

// CSRF (spójny klucz: csrf_token)
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  logg('debug', 'payments.widget', 'csrf_issued', [
    'order_id' => $order_id,
    'owner_id' => $owner_id,
  ], ['context' => 'orders']);
}
$csrf = $_SESSION['csrf_token'];

// Pobierz listę grup (prosto: po order_id, filtr ownera po JOIN z orders)
$groups = [];
try {
  $stmt = $pdo->prepare("
        SELECT og.id
        FROM order_groups og
        JOIN orders o ON o.id = og.order_id AND o.owner_id = :oid
        WHERE og.order_id = :order_id
        ORDER BY og.id ASC
    ");
  $stmt->execute([':oid' => $owner_id, ':order_id' => $order_id]);
  $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  logg('info', 'payments.widget', 'groups_loaded', [
    'order_id' => $order_id,
    'owner_id' => $owner_id,
    'groups_count' => count($groups),
  ], ['context' => 'orders']);
} catch (Throwable $e) {
  log_exception($e, ['where' => 'payments.widget.groups_query', 'order_id' => $order_id, 'owner_id' => $owner_id]);
  $groups = [];
}
?>
<div id="paymentWidget" class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm"
  data-order-id="<?= (int)$order_id ?>" data-owner-id="<?= (int)$owner_id ?>">
  <div class="flex items-center justify-between px-4 py-3 border-b">
    <h2 class="text-base font-semibold">Płatności</h2>
    <div class="flex gap-2">
      <button id="btn-refresh-tx" type="button" class="px-3 py-1.5 text-sm rounded-md border border-stone-300 hover:bg-stone-50">
        Odśwież
      </button>
      <button id="btn-add-tx" type="button" class="px-3 py-1.5 text-sm rounded-md bg-emerald-600 text-white hover:bg-emerald-700">
        Dodaj transakcję
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-3 p-4">
    <div class="p-3 rounded-lg bg-stone-50">
      <div class="text-xs text-stone-500">Suma pozycji</div>
      <div class="text-lg font-semibold"><span id="pw-items-total">—</span> zł</div>
    </div>
    <div class="p-3 rounded-lg bg-stone-50">
      <div class="text-xs text-stone-500">Zapłacono</div>
      <div class="text-lg font-semibold text-emerald-700"><span id="pw-paid">—</span> zł</div>
    </div>
    <div class="p-3 rounded-lg bg-stone-50">
      <div class="text-xs text-stone-500">Do zapłaty</div>
      <div class="text-lg font-semibold text-amber-700"><span id="pw-due">—</span> zł</div>
    </div>
  </div>

  <div class="px-4 pb-4 text-sm text-stone-600">
    Ostatnia płatność: <span id="pw-last">—</span>
  </div>

  <div class="px-4 pb-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left text-stone-500">
          <th class="py-2 pr-4">Data</th>
          <th class="py-2 pr-4">Typ</th>
          <th class="py-2 pr-4">Status</th>
          <th class="py-2 pr-4">Kwota</th>
          <th class="py-2 pr-4">Metoda</th>
          <th class="py-2 pr-4">Provider Tx</th>
        </tr>
      </thead>
      <tbody id="pw-tx-body">
        <tr>
          <td colspan="6" class="py-4 text-stone-400 text-center">Brak transakcji</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php
// Przekazujemy do modala: listę grup + csrf + identyfikatory
$__payment_widget_ctx = [
  'order_id' => $order_id,
  'owner_id' => $owner_id,
  'csrf'     => $csrf,
  'groups'   => $groups,
];
include __DIR__ . '/_payment_modal_and_script.php';
