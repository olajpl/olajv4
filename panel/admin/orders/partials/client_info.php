<?php
// admin/orders/partials/client_info.php — safe & enum-ready

// 🛡️ Soft-guardy: doprowadź wejście do przewidywalnych form
$client       = is_array($client       ?? null) ? $client       : [];
$order        = is_array($order        ?? null) ? $order        : [];
$firstAddress = is_array($firstAddress ?? null) ? $firstAddress : null;
$shippingName = $shippingName ?? null;

// 🧼 e() — jeśli nie jest już zdefiniowane globalnie
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Priorytety:
// 1) $client z engine
// 2) aliasy z SELECT-a: client__*
// 3) stary fallback w $order: client_*
$clientName  =
    (($client['name']  ?? '') !== '') ? (string)$client['name']
  : (!empty($order['client__name'])   ? (string)$order['client__name']   : (string)($order['client_name']  ?? '—'));

$clientEmail =
    (($client['email'] ?? '') !== '') ? (string)$client['email']
  : (!empty($order['client__email'])  ? (string)$order['client__email']  : (string)($order['client_email'] ?? ''));

$clientPhone =
    (($client['phone'] ?? '') !== '') ? (string)$client['phone']
  : (!empty($order['client__phone'])  ? (string)$order['client__phone']  : (string)($order['client_phone'] ?? ''));

$clientToken =
    (($client['token'] ?? '') !== '') ? (string)$client['token']
  : (!empty($order['client__token'])  ? (string)$order['client__token']  : (string)($order['client_token'] ?? ''));

// Dostawa
$shipName = $shippingName ? (string)$shippingName : null;
$addr     = $firstAddress ?: null;
?>
<div class="rounded-xl border border-stone-200">
  <div class="px-4 py-3 border-b bg-stone-50 font-semibold">Klient i dostawa</div>

  <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
    <!-- Klient -->
    <div>
      <div class="text-stone-500">Klient</div>
      <div class="font-medium"><?= e($clientName !== '' ? $clientName : '—') ?></div>

      <?php
        $lineBits = [];
        if ($clientEmail !== '') $lineBits[] = e($clientEmail);
        if ($clientPhone !== '') $lineBits[] = e($clientPhone);
        $line = implode(' • ', $lineBits);
      ?>
      <div class="text-stone-600"><?= $line !== '' ? $line : '—' ?></div>

      <?php if ($clientToken !== ''): ?>
        <div class="text-stone-500 mt-1">
          Token: <code><?= e($clientToken) ?></code>
        </div>
      <?php endif; ?>
    </div>

    <!-- Dostawa -->
    <div>
      <div class="text-stone-500">Dostawa</div>
      <div><?= $shipName ? e($shipName) : '—' ?></div>

      <?php if ($addr): ?>
        <div class="mt-1 text-stone-700">
          <?= e((string)($addr['full_name']   ?? '')) ?><br>
          <?= e((string)($addr['street']      ?? '')) ?><br>
          <?= e((string)($addr['postal_code'] ?? '')) ?>
          <?= e((string)($addr['city']        ?? '')) ?><br>
          <?= e((string)($addr['country']     ?? '')) ?>
        </div>
      <?php else: ?>
        <div class="text-stone-500">
          Brak adresu.
          <span class="text-stone-400">Ustaw adres w zamówieniu lub wybierz domyślny adres klienta.</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
