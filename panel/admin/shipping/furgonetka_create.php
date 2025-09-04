<?php
// admin/shipping/furgonetka_create.php
// 1) Nadaj przez /v2/shipments (NIE Universal), mądrze ustawiając service_id
// 2) Obsługa Paczkomatu (wymaga point/locker code) i limitu 25 kg
// 3) Full logging flog() → logg()/OlajV4Logger

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/shipping_furgonetka.php';
require_once __DIR__ . '/../../api/furgonetka/furgonetka_api.php';
require_once __DIR__ . '/../../includes/log.php';

if (!function_exists('flog')) {
  function flog(string $msg, string $lvl = 'info', array $ctx = []): void
  {
    $meta = ['event' => 'furgonetka', 'source' => 'shipping_module'];
    if (function_exists('logg')) {
      logg($lvl, 'shipping.furgonetka', $msg, $ctx, $meta);
      return;
    }
    if (class_exists('OlajV4Logger')) {
      OlajV4Logger::log("{$lvl}.shipping.furgonetka.{$msg}", $ctx + $meta);
      return;
    }
    error_log('[shipping.furgonetka][' . $lvl . '][' . $msg . '] ' . json_encode($ctx + $meta, JSON_UNESCAPED_UNICODE));
  }
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Kontekst
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

flog('create.start', 'info', ['owner_id' => $ownerId, 'user_id' => $userId, 'order_id' => $orderId, 'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI']);

if ($ownerId <= 0) {
  http_response_code(403);
  exit('Brak kontekstu właściciela');
}
if ($orderId <= 0) {
  http_response_code(400);
  exit('Brak order_id');
}

// CSRF (zgodne z Twoim widokiem)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
  if (empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf'])) {
    $_SESSION['csrf_token'] = $_SESSION['csrf'];
  }
  if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
    flog('create.csrf_fail', 'error', ['post_keys' => array_keys($_POST)]);
    http_response_code(403);
    exit('CSRF');
  }
}

// Zamówienie + grupa + adres
$st = $pdo->prepare("SELECT o.id, o.owner_id, o.client_id FROM orders o WHERE o.id=? AND o.owner_id=? LIMIT 1");
$st->execute([$orderId, $ownerId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) exit('Nie znaleziono zamówienia');

$st = $pdo->prepare("SELECT og.id AS order_group_id FROM order_groups og WHERE og.order_id=? ORDER BY og.created_at ASC LIMIT 1");
$st->execute([$orderId]);
$group = $st->fetch(PDO::FETCH_ASSOC);
if (!$group) exit('Brak grupy');
$groupId = (int)$group['order_group_id'];

$st = $pdo->prepare("SELECT * FROM shipping_addresses WHERE order_group_id=? LIMIT 1");
$st->execute([$groupId]);
$addr = $st->fetch(PDO::FETCH_ASSOC);
if (!$addr) exit('Brak danych adresowych');

// Klient (fallback)
$st = $pdo->prepare("SELECT name, email, phone FROM clients WHERE id=? LIMIT 1");
$st->execute([(int)$order['client_id']]);
$client = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => null, 'email' => null, 'phone' => null];

// Waga z pozycji
$st = $pdo->prepare("
  SELECT COALESCE(SUM(COALESCE(oi.quantity,1) * COALESCE(p.weight,0)),0) AS total_kg
  FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_group_id = ?
");
$st->execute([$groupId]);
$totalKg = (float)$st->fetchColumn();
if ($totalKg <= 0) $totalKg = 1.00;

// Integracja nadawcy
$integration = null;
try {
  $st = $pdo->prepare("SELECT * FROM shipping_integrations WHERE owner_id=? AND broker='furgonetka' LIMIT 1");
  $st->execute([$ownerId]);
  $integration = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { /* opcjonalnie */
}

$sender_name  = $integration['sender_name']  ?? ($_SESSION['user']['company_name'] ?? 'Nadawca');
$sender_email = $integration['sender_email'] ?? ($client['email'] ?? 'no-reply@olaj.pl');
$sender_phone = $integration['sender_phone'] ?? ($client['phone'] ?? '000000000');
$sender_addr  = $integration['sender_address'] ?? 'Ulica 1, 00-000 Miasto';

$senderStreet = $sender_addr;
$senderPostcode = '00-000';
$senderCity = 'Miasto';
if (preg_match('/(.+),\s*([0-9]{2}-[0-9]{3})\s+(.+)/', (string)$sender_addr, $m)) {
  $senderStreet = trim($m[1]);
  $senderPostcode = trim($m[2]);
  $senderCity = trim($m[3]);
}

// ===== PRZECHWYĆ DANE z mini‑modułu =====
$svcHint = (string)($_POST['pkg_service_hint'] ?? ''); // np. 'INPOST_PACZKOMAT'
$len     = isset($_POST['pkg_length_cm']) ? (int)$_POST['pkg_length_cm'] : null;
$wid     = isset($_POST['pkg_width_cm'])  ? (int)$_POST['pkg_width_cm']  : null;
$hei     = isset($_POST['pkg_height_cm']) ? (int)$_POST['pkg_height_cm'] : null;
$wkg     = isset($_POST['pkg_weight_kg']) ? (float)$_POST['pkg_weight_kg'] : null;

$packageCount = max(1, (int)($_POST['package_count'] ?? 1));
$comment      = trim((string)($_POST['comment'] ?? ''));
if ($svcHint === 'INPOST' /* lub locker_code podany */) {
  $serviceCode = 'INPOST';   // NIE: 'INPOST_PACZKOMAT', NIE: 'paczkomat'
}
// Locker/point (jeśli paczkomat)
$locker = trim((string)($addr['locker_code'] ?? $addr['receiver_locker_code'] ?? $_POST['receiver_locker_code'] ?? ''));

// Budowa paczek
$baseWeight = $wkg ? round($wkg, 2) : round($totalKg, 2);
$baseDims   = ['length' => $len ?: 25, 'width' => $wid ?: 20, 'height' => $hei ?: 15];
$packages = [];
if ($packageCount > 1) {
  $per = max(0.01, round($baseWeight / $packageCount, 2));
  if ($svcHint === 'INPOST_PACZKOMAT' && $per > 25) {
    $_SESSION['error_message'] = "❌ Każda paczka do Paczkomatu ≤ 25 kg.";
    header("Location: ../orders/view.php?id=" . $orderId);
    exit;
  }
  for ($i = 0; $i < $packageCount; $i++) $packages[] = ['weight' => $per] + $baseDims;
} else {
  if ($svcHint === 'INPOST_PACZKOMAT' && $baseWeight > 25) {
    $_SESSION['error_message'] = "❌ Waga dla Paczkomatu ≤ 25 kg.";
    header("Location: ../orders/view.php?id=" . $orderId);
    exit;
  }
  $packages[] = ['weight' => $baseWeight] + $baseDims;
}

// Dane odbiorcy
$receiver_name  = $addr['full_name'] ?: ($client['name'] ?? 'Klient');
$receiver_email = $addr['email']     ?: ($client['email'] ?? null);
$receiver_phone = $addr['phone']     ?: ($client['phone'] ?? null);
$receiver_street   = trim((string)($addr['street'] ?? 'Brak'));
$receiver_postcode = trim((string)($addr['postcode'] ?? '00-000'));
$receiver_city     = trim((string)($addr['city'] ?? 'Miasto'));

// INSERT shipping_labels (pending)
$pdo->beginTransaction();
try {
  $ins = $pdo->prepare("
      INSERT INTO shipping_labels
        (owner_id, order_id, order_group_id, client_id, provider, status,
         receiver_name, receiver_phone, receiver_email,
         receiver_street, receiver_postcode, receiver_city, receiver_locker_code,
         package_weight, package_count, service_code, order_reference, comment,
         created_at, updated_at)
      VALUES
        (:owner_id,:order_id,:group_id,:client_id,'furgonetka','pending',
         :rname,:rphone,:remail,
         :rstreet,:rpost,:rcity,:rlocker,
         :w,:cnt,:svc,:ref,:comment,
         NOW(),NOW())
    ");
  $ins->execute([
    'owner_id' => $ownerId,
    'order_id' => $orderId,
    'group_id' => $groupId,
    'client_id' => ($order['client_id'] ?? null),
    'rname' => $receiver_name,
    'rphone' => $receiver_phone,
    'remail' => $receiver_email,
    'rstreet' => $receiver_street,
    'rpost' => $receiver_postcode,
    'rcity' => $receiver_city,
    'rlocker' => $locker ?: null,
    'w' => $packages[0]['weight'],
    'cnt' => $packageCount,
    // Tu wciąż trzymamy „service_code” jako hint – prawdziwe id w payloadzie /v2 ustawimy niżej
    'svc' => $svcHint ?: ($integration['default_service_code'] ?? 'inpost'),
    'ref' => 'ZAM-' . $orderId,
    'comment' => $comment ?: null,
  ]);
  $labelId = (int)$pdo->lastInsertId();
  $pdo->commit();
} catch (\Throwable $e) {
  $pdo->rollBack();
  flog('create.label_insert_error', 'error', ['error' => $e->getMessage()]);
  $_SESSION['error_message'] = "❌ Błąd utworzenia etykiety: " . $e->getMessage();
  header("Location: ../orders/view.php?id=" . $orderId);
  exit;
}

// ======= Przygotowanie payloadu /v2 =======
// /v2 wymaga **service_id** (nie „service”) oraz – dla paczkomatu – punktu (locker code).
$api = new FurgonetkaAPI(['pdo' => $pdo, 'owner_id' => $ownerId]);

// Heurystyka service_id:
// 1) Jeżeli paczkomat → spróbuj znaleźć usługę po nazwie (np. zawierającej „InPost” i „Paczkomat”)
// 2) W innym wypadku – jeżeli w integracji jest default_service_id, użyj; jeśli nie – spróbuj znaleźć po nazwie z default_service_code
$serviceId = null;
try {
  if ($svcHint === 'inpost') {
    // spróbuj kilka wariantów nazw; różne konta mają różnie
    foreach (['paczkomat', 'paczkomaty', 'locker', 'parcel locker', 'inpost locker', 'inpost paczkomat', 'inpost'] as $needle) {
      $serviceId = $api->findServiceIdByName($needle);
      if ($serviceId) break;
    }
  } else {
    if (!empty($integration['default_service_id'])) {
      $serviceId = (int)$integration['default_service_id'];
    } elseif (!empty($integration['default_service_code'])) {
      $serviceId = $api->findServiceIdByName((string)$integration['default_service_code']);
    }
  }
} catch (\Throwable $e) {
  flog('services.search_fail', 'warning', ['error' => $e->getMessage()]);
}

if (!$serviceId) {
  // zaloguj listę usług, żebyśmy zobaczyli co twoje konto zwraca
  $services = [];
  try {
    $services = $api->listServices();
  } catch (\Throwable $e) {
  }
  flog('services.not_found', 'error', ['hint' => $svcHint, 'services' => $services]);
  $_SESSION['error_message'] = "Nie udało się dobrać usługi (service_id). Wejdź w Mocne Logi → shipping.furgonetka i podeślij listę usług.";
  header("Location: ../orders/view.php?id=" . $orderId);
  exit;
}


// Uwaga: różne integracje /v2 używają pól o zbliżonej nazwie.
// Najczęściej akceptowane atrybuty (zgodne z tym, co zwraca 400):
// - service_id (int) – WYMAGANY
// - sender, receiver (z address)
// - packages (tablica z weight + dimensions)
// - pickup/dropoff/point dla paczkomatu – nazwy bywają różne, więc wyślemy popularne warianty (backend zignoruje nieznane).
$payload = [
  'service_id' => $serviceId,                        // << kluczowe
  'reference'  => 'ZAM-' . $orderId,
  'sender'     => [
    'company_name' => $sender_name,
    'email'        => $sender_email,
    'phone'        => $sender_phone,
    'address'      => [
      'street'       => $senderStreet,
      'postcode'     => $senderPostcode,
      'city'         => $senderCity,
      'country_code' => 'PL',
    ],
  ],
  'receiver'   => [
    'name'   => $receiver_name,
    'email'  => $receiver_email,
    'phone'  => $receiver_phone,
    'address' => [
      'street'       => $receiver_street,
      'postcode'     => $receiver_postcode,
      'city'         => $receiver_city,
      'country_code' => 'PL',
    ],
  ],
  'packages'   => array_map(function ($p) {
    return [
      'weight' => (float)$p['weight'],
      'length' => (int)$p['length'],
      'width'  => (int)$p['width'],
      'height' => (int)$p['height'],
    ];
  }, $packages),
];

// Paczkomat – dołóż identyfikator punktu (wrzucamy 2 popularne klucze – serwer i tak odfiltruje)
if ($svcHint === 'INPOST_PACZKOMAT' && $locker !== '') {
  $payload['point'] = ['code' => $locker];
  $payload['dropoff'] = ['point_code' => $locker]; // alternatywny klucz spotykany w /v2
}

if ($comment !== '') {
  $payload['comment'] = $comment;
}

flog('create.payload', 'debug', ['label_id' => $labelId, 'payload' => $payload]);

try {
  $res = $api->createShipment($payload);
  flog('create.api_ok', 'info', ['label_id' => $labelId, 'res' => $res]);

  $externalId     = $res['id']              ?? $res['external_id'] ?? null;
  $trackingNumber = $res['tracking_number'] ?? ($res['tracking']['number'] ?? null);
  $price          = isset($res['price']) ? (float)$res['price'] : null;
  $hasLabelUrl    = isset($res['label_url']) || isset($res['label']['url']) || isset($res['label']);

  $newStatus = $hasLabelUrl ? 'label_ready' : 'ordered';

  $up = $pdo->prepare("
      UPDATE shipping_labels
      SET status=:st, external_id=:eid, tracking_number=:tn, price=:pr, error_message=NULL, updated_at=NOW()
      WHERE id=:id
    ");
  $up->execute([
    'st' => $newStatus,
    'eid' => $externalId,
    'tn' => $trackingNumber,
    'pr' => $price,
    'id' => $labelId
  ]);

  // Opcjonalny JSON w orders.shipping_info
  try {
    $info = [
      'provider' => 'furgonetka',
      'label_id' => $labelId,
      'external_id' => $externalId,
      'tracking_number' => $trackingNumber,
      'price' => $price,
      'status' => $newStatus
    ];
    $st = $pdo->prepare("UPDATE orders SET shipping_info=:j WHERE id=:oid");
    $st->execute(['j' => json_encode($info, JSON_UNESCAPED_UNICODE), 'oid' => $orderId]);
  } catch (\Throwable $e) { /* best-effort */
  }

  // Historia
  try {
    $st = $pdo->prepare("INSERT INTO order_status_log (order_id,status,changed_by_user_id,created_at) VALUES (?,?,?,NOW())");
    $st->execute([$orderId, "📦 Przesyłka nadana (Furgonetka). Tracking: " . ($trackingNumber ?: '—'), $userId]);
  } catch (\Throwable $e) {
  }

  $_SESSION['success_message'] = "✅ Przesyłka nadana. Numer: " . ($trackingNumber ?: 'w przygotowaniu');
  header("Location: ../orders/view.php?id=" . $orderId);
  exit;
} catch (\Throwable $e) {
  flog('create.api_err', 'error', ['label_id' => $labelId, 'error' => $e->getMessage()]);
  try {
    $st = $pdo->prepare("UPDATE shipping_labels SET status='error', error_message=:m, updated_at=NOW() WHERE id=:id");
    $st->execute(['m' => mb_substr($e->getMessage(), 0, 250), 'id' => $labelId]);
  } catch (\Throwable $e2) {
  }

  $_SESSION['error_message'] = "❌ Błąd nadania: " . $e->getMessage();
  header("Location: ../orders/view.php?id=" . $orderId);
  exit;
}
