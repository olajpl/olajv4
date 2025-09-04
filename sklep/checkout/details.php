<?php

declare(strict_types=1);
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/**
 * Loader musi:
 *  - ustawić $pdo (PDO)
 *  - zwrócić $checkout z kluczami: order_id, order_group_id, owner_id, client_id, order_status, token
 *  - egzekwować guard (checkout_completed => redirect do thank_you)
 */
require_once __DIR__ . '/../includes/checkout_loader.php'; // ← ładuje db + helpers + $checkout
require_once __DIR__ . '/../includes/log.php';             // wlog()/logg()

/* ─────────────────────────────────────────────────────────────────────────────
   Kontekst z loadera
   ─────────────────────────────────────────────────────────────────────────── */
$orderId  = (int)($checkout['order_id'] ?? 0);
$ownerId  = (int)($checkout['owner_id'] ?? 0);
$clientId = (int)($checkout['client_id'] ?? 0);
$groupId  = (int)($checkout['order_group_id'] ?? 0);
$token    = (string)($checkout['token'] ?? '');
$status   = (string)($checkout['order_status'] ?? '');

/* ─────────────────────────────────────────────────────────────────────────────
   Guards (legacy) — pas zapięty i szelki
   ─────────────────────────────────────────────────────────────────────────── */
$legacyClosed = ['wyslane', 'wysłane', 'zrealizowane', 'anulowane', 'zarchiwizowane', 'gotowe_do_wysyłki', 'w_realizacji'];
if (in_array($status, $legacyClosed, true)) {
  header('Location: index.php?token=' . urlencode($token));
  exit;
}

/* ─────────────────────────────────────────────────────────────────────────────
   Metoda dostawy (DB)
   ─────────────────────────────────────────────────────────────────────────── */
$q = $pdo->prepare("SELECT shipping_id, shipping_address_id FROM orders WHERE id = :oid LIMIT 1");
$q->execute([':oid' => $orderId]);
$orderRow = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$shipping_method_id   = (int)($orderRow['shipping_id'] ?? 0);
$current_address_id   = (int)($orderRow['shipping_address_id'] ?? 0);
if ($shipping_method_id <= 0) {
  header('Location: index.php?token=' . urlencode($token));
  exit;
}

$stmt = $pdo->prepare("
    SELECT id, owner_id, name, type, default_price, carrier
    FROM shipping_methods
    WHERE id = :id AND owner_id = :owner_id AND active = 1
    LIMIT 1
");
$stmt->execute([':id' => $shipping_method_id, ':owner_id' => $ownerId]);
$shipping_method = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$shipping_method) {
  header('Location: index.php?token=' . urlencode($token));
  exit;
}

$methodType = strtolower((string)($shipping_method['type'] ?? '')); // 'courier' | 'pickup' | 'locker' | 'other'
$carrier    = strtolower((string)($shipping_method['carrier'] ?? ''));

$is_locker = ($methodType === 'locker');   // InPost Paczkomat
$is_pickup = ($methodType === 'pickup');   // Odbiór osobisty
$is_courier = ($methodType === 'courier');

/* ─────────────────────────────────────────────────────────────────────────────
   Adres wysyłki: pobierz aktualnie przypięty do zamówienia (orders.shipping_address_id)
   ─────────────────────────────────────────────────────────────────────────── */
$address = null;
if ($current_address_id > 0) {
  $st = $pdo->prepare("SELECT * FROM shipping_addresses WHERE id = :id AND owner_id = :oid LIMIT 1");
  $st->execute([':id' => $current_address_id, ':oid' => $ownerId]);
  $address = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Prefill (bez insertu) z client_addresses → clients */
$prefill = [];
if (!$address && $clientId > 0) {
  $ca = $pdo->prepare("
      SELECT full_name, phone, email, street, postcode, city, locker_code
      FROM client_addresses
      WHERE client_id = :cid
      ORDER BY is_default DESC, updated_at DESC, id DESC
      LIMIT 1
    ");
  $ca->execute([':cid' => (int)$clientId]);
  $pref = $ca->fetch(PDO::FETCH_ASSOC) ?: [];

  if ($pref) {
    // mapowanie na nasz schemat shipping_addresses
    $prefill = [
      'name'        => (string)($pref['full_name'] ?? ''),
      'phone'       => (string)($pref['phone'] ?? ''),
      'email'       => (string)($pref['email'] ?? ''),
      'street'      => (string)($pref['street'] ?? ''),
      'postal_code' => (string)($pref['postcode'] ?? ''),
      'city'        => (string)($pref['city'] ?? ''),
      'locker_id'   => (string)($pref['locker_code'] ?? ''),
      'type'        => $is_locker ? 'locker' : ($is_pickup ? 'pickup' : 'home'),
    ];
  } else {
    $cl = $pdo->prepare("SELECT name, phone, email FROM clients WHERE id = :cid LIMIT 1");
    $cl->execute([':cid' => (int)$clientId]);
    $c = $cl->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($c) {
      $prefill = [
        'name'        => (string)($c['name'] ?? ''),
        'phone'       => (string)($c['phone'] ?? ''),
        'email'       => (string)($c['email'] ?? ''),
        'type'        => $is_locker ? 'locker' : ($is_pickup ? 'pickup' : 'home'),
      ];
    }
  }
}

/* ─────────────────────────────────────────────────────────────────────────────
   CSRF
   ─────────────────────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_checkout'])) $_SESSION['csrf_checkout'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_checkout'];

/* ─────────────────────────────────────────────────────────────────────────────
   UPSERT shipping_addresses + podpięcie do orders.shipping_address_id
   ─────────────────────────────────────────────────────────────────────────── */
function upsertOrderShippingAddress(PDO $pdo, int $orderId, int $ownerId, int $clientId, array $a): int
{
  // Strategia:
  // - jeśli $a['id'] istnieje → UPDATE tego rekordu
  // - w przeciwnym razie INSERT nowego rekordu (is_default = 1 nie jest wymagane)
  // - zawsze potem UPDATE orders.shipping_address_id = :newId
  $hasId = (int)($a['id'] ?? 0);

  if ($hasId > 0) {
    $sql = "UPDATE shipping_addresses
                SET name=:name, email=:email, phone=:phone, country=:country, city=:city,
                    postal_code=:postal_code, street=:street, building_no=:building_no, apartment_no=:apartment_no,
                    notes=:notes, locker_id=:locker_id, type=:type, updated_at=NOW()
                WHERE id=:id AND owner_id=:owner_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':name'         => (string)($a['name'] ?? ''),
      ':email'        => (string)($a['email'] ?? ''),
      ':phone'        => (string)($a['phone'] ?? ''),
      ':country'      => (string)($a['country'] ?? 'Polska'),
      ':city'         => (string)($a['city'] ?? ''),
      ':postal_code'  => (string)($a['postal_code'] ?? ''),
      ':street'       => (string)($a['street'] ?? ''),
      ':building_no'  => (string)($a['building_no'] ?? ''),
      ':apartment_no' => (string)($a['apartment_no'] ?? ''),
      ':notes'        => (string)($a['notes'] ?? ''),
      ':locker_id'    => (string)($a['locker_id'] ?? ''),
      ':type'         => (string)($a['type'] ?? 'home'),
      ':id'           => $hasId,
      ':owner_id'     => $ownerId,
    ]);
    $newId = $hasId;
  } else {
    $sql = "INSERT INTO shipping_addresses
                (client_id, owner_id, name, email, phone, country, city, postal_code, street, building_no, apartment_no, notes, locker_id, type, is_default, created_at)
                VALUES
                (:client_id, :owner_id, :name, :email, :phone, :country, :city, :postal_code, :street, :building_no, :apartment_no, :notes, :locker_id, :type, 0, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':client_id'    => $clientId ?: null,
      ':owner_id'     => $ownerId,
      ':name'         => (string)($a['name'] ?? ''),
      ':email'        => (string)($a['email'] ?? ''),
      ':phone'        => (string)($a['phone'] ?? ''),
      ':country'      => (string)($a['country'] ?? 'Polska'),
      ':city'         => (string)($a['city'] ?? ''),
      ':postal_code'  => (string)($a['postal_code'] ?? ''),
      ':street'       => (string)($a['street'] ?? ''),
      ':building_no'  => (string)($a['building_no'] ?? ''),
      ':apartment_no' => (string)($a['apartment_no'] ?? ''),
      ':notes'        => (string)($a['notes'] ?? ''),
      ':locker_id'    => (string)($a['locker_id'] ?? ''),
      ':type'         => (string)($a['type'] ?? 'home'),
    ]);
    $newId = (int)$pdo->lastInsertId();
  }

  $u = $pdo->prepare("UPDATE orders SET shipping_address_id=:sid WHERE id=:oid LIMIT 1");
  $u->execute([':sid' => $newId, ':oid' => $orderId]);

  logg('info', 'checkout.address', 'upsert', ['order_id' => $orderId, 'client_id' => $clientId, 'shipping_address_id' => $newId]);
  return $newId;
}

/* ─────────────────────────────────────────────────────────────────────────────
   POST: walidacja + UPSERT
   ─────────────────────────────────────────────────────────────────────────── */
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!hash_equals((string)$CSRF, (string)$csrf)) {
    http_response_code(403);
    exit('Niepoprawny token bezpieczeństwa.');
  }

  // Wspólne
  $name   = trim((string)($_POST['name']  ?? ''));
  $phone  = trim((string)($_POST['phone'] ?? ''));
  $email  = trim((string)($_POST['email'] ?? ''));

  // Kurier/adres
  $street       = trim((string)($_POST['street'] ?? ''));
  $building_no  = trim((string)($_POST['building_no'] ?? ''));
  $apartment_no = trim((string)($_POST['apartment_no'] ?? ''));
  $postal_code  = trim((string)($_POST['postal_code'] ?? ''));
  $city         = trim((string)($_POST['city'] ?? ''));

  // Locker
  $locker_id = trim((string)($_POST['locker_id'] ?? ''));

  // Uwagi
  $notes = trim((string)($_POST['notes'] ?? ''));

  // Walidacje bazowe
  if ($name === '' || $phone === '') {
    $error = 'Proszę podać imię i nazwisko oraz numer telefonu.';
  }

  if ($error === '' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Nieprawidłowy adres e-mail.';
  }
  if ($error === '' && $phone !== '' && !preg_match('/^[0-9 \-\+\(\)]{6,20}$/', $phone)) {
    $error = 'Nieprawidłowy format telefonu.';
  }

  // Walidacje zależne od typu dostawy
  if ($error === '') {
    if ($is_locker) {
      if ($locker_id === '') $error = 'Podaj kod Paczkomatu (np. WAW01A).';
      // adres nie jest wymagany dla lockerów
      $street = $building_no = $apartment_no = $postal_code = $city = '';
    } elseif ($is_pickup) {
      // odbiór osobisty – adres niepotrzebny
      $street = $building_no = $apartment_no = $postal_code = $city = '';
      $locker_id = '';
    } else { // courier/other
      if ($street === '' || $postal_code === '' || $city === '') {
        $error = 'Proszę wypełnić pola adresowe: ulica, kod pocztowy, miasto.';
      }
      $locker_id = '';
    }
  }

  if ($error === '') {
    $addr = [
      'id'           => $current_address_id ?: null, // jeśli był przypięty, zaktualizujemy
      'name'         => $name,
      'phone'        => $phone,
      'email'        => $email,
      'country'      => 'Polska',
      'city'         => $city,
      'postal_code'  => $postal_code,
      'street'       => $street,
      'building_no'  => $building_no,
      'apartment_no' => $apartment_no,
      'notes'        => $notes,
      'locker_id'    => $locker_id,
      'type'         => $is_locker ? 'locker' : ($is_pickup ? 'pickup' : 'home'),
    ];

    // zapis + podpięcie do zamówienia
    $newId = upsertOrderShippingAddress($pdo, $orderId, $ownerId, $clientId, $addr);

    // PRZEJŚCIE DALEJ
    header('Location: summary.php?token=' . urlencode($token));
    exit;
  }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Helper echo + Prefill (mapowanie do inputów)
   ─────────────────────────────────────────────────────────────────────────── */
function e(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES);
}

$val_name        = $address['name']        ?? ($prefill['name']        ?? '');
$val_phone       = $address['phone']       ?? ($prefill['phone']       ?? '');
$val_email       = $address['email']       ?? ($prefill['email']       ?? '');
$val_street      = $address['street']      ?? ($prefill['street']      ?? '');
$val_building_no = $address['building_no'] ?? ($prefill['building_no'] ?? '');
$val_apartment   = $address['apartment_no'] ?? ($prefill['apartment_no'] ?? '');
$val_postal_code = $address['postal_code'] ?? ($prefill['postal_code'] ?? '');
$val_city        = $address['city']        ?? ($prefill['city']        ?? '');
$val_notes       = $address['notes']       ?? ($prefill['notes']       ?? '');
$val_locker_id   = $address['locker_id']   ?? ($prefill['locker_id']   ?? '');
?>
<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8">
  <title>Dane do wysyłki</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen py-4 px-2 sm:py-8 sm:px-4">
  <div class="max-w-xl mx-auto bg-white p-4 sm:p-6 rounded-xl shadow">
    <h1 class="text-xl sm:text-2xl font-semibold mb-4">
      <?= $shipping_method ? "📦 Dane dla: " . e((string)$shipping_method['name']) : "📦 Dane do wysyłki" ?>
    </h1>

    <?php if (!empty($error)): ?>
      <div class="mb-4 p-3 bg-red-100 text-red-800 rounded text-sm sm:text-base">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-3 sm:space-y-4">
      <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">

      <input type="text" name="name" value="<?= e($val_name) ?>"
        placeholder="Imię i nazwisko" required
        class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">

      <input type="tel" name="phone" value="<?= e($val_phone) ?>"
        placeholder="Telefon" required
        class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">

      <input type="email" name="email" value="<?= e($val_email) ?>"
        placeholder="E-mail"
        class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">

      <?php if ($is_locker): ?>
        <!-- Paczkomat -->
        <div class="rounded-lg border p-3 bg-indigo-50/50">
          <div class="text-sm font-medium mb-2">📦 Odbiór w Paczkomacie InPost</div>

          <input type="text" name="locker_id" value="<?= e($val_locker_id) ?>"
            placeholder="Kod Paczkomatu (np. WAW01A)" required
            class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base mb-2">

          <div class="mt-2 text-xs text-gray-500">
            Nie pamiętasz kodu? <a class="text-indigo-600 underline" target="_blank" rel="noopener" href="https://inpost.pl/znajdz-paczkomat">znajdź Paczkomat</a>.
          </div>
        </div>
      <?php elseif ($is_courier || $methodType === 'other'): ?>
        <!-- Wysyłka kurier/list -->
        <input type="text" name="street" value="<?= e($val_street) ?>"
          placeholder="Ulica" required
          class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">
        <div class="grid grid-cols-2 gap-2">
          <input type="text" name="building_no" value="<?= e($val_building_no) ?>"
            placeholder="Nr domu" required
            class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">
          <input type="text" name="apartment_no" value="<?= e($val_apartment) ?>"
            placeholder="Nr lokalu"
            class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">
        </div>
        <div class="grid grid-cols-2 gap-2">
          <input type="text" name="postal_code" value="<?= e($val_postal_code) ?>"
            placeholder="Kod pocztowy" required
            class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">
          <input type="text" name="city" value="<?= e($val_city) ?>"
            placeholder="Miasto" required
            class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base">
        </div>
      <?php else: ?>
        <!-- Odbiór osobisty -->
        <div class="rounded-lg border p-3 bg-emerald-50/50 text-sm">
          Odbiór osobisty — adres nie jest wymagany.
        </div>
      <?php endif; ?>

      <textarea name="notes" placeholder="Uwagi do dostawy"
        class="w-full border rounded px-3 py-2 sm:py-3 text-sm sm:text-base"><?= e($val_notes) ?></textarea>

      <button type="submit"
        class="w-full bg-blue-600 text-white py-2 sm:py-3 px-4 rounded-lg hover:bg-blue-700 text-sm sm:text-base">
        Dalej ➡️
      </button>
    </form>

    <div class="mt-3 text-center">
      <a class="text-sm text-slate-600 underline" href="index.php?token=<?= urlencode($token) ?>">⟵ wróć</a>
    </div>
  </div>
</body>

</html>