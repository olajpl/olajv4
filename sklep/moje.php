<?php
// shop/moje.php — panel klienta (mobile-first) — Zgodny z Bazą V2 (2025-08, „odzysk + harden”)
// Funkcje:
// - Odczyt klienta po tokenie (client_token lub checkout/group -> klient).
// - Lista paczek (order_groups) + statusy (orders.order_status) + ostatni payments.status.
// - Formularz domyślnych metod/pól + zapis do client_info i opcjonalnie client_addresses (is_default=1).
// - Defensywne wykrywanie kolumn (qty/quantity, unit_price/price, total_price, weight itp.).
// - Brak 1054/HY093 – SQL przygotowane ostrożnie. Logi przez wlog/logg jeżeli dostępne.

declare(strict_types=1);
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php'; // jeśli nie ma — usuń ten require
require_once __DIR__ . '/engine/Orders/ClientEngine.php';
require_once __DIR__ . '/engine/Checkout/CheckoutResolver.php';

use Engine\Orders\ClientEngine;
use Engine\Checkout\CheckoutResolver;

$clientEngine = new ClientEngine($pdo);
$resolver     = new CheckoutResolver();
/* ──────────────────────────────────────────────────────────────
 * Logger shims
 * ──────────────────────────────────────────────────────────── */
if (!function_exists('wlog')) {
    function wlog($msg, array $ctx = []): void {}
}
if (!function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $context = []): void {}
}

/* ──────────────────────────────────────────────────────────────
 * Helpers
 * ──────────────────────────────────────────────────────────── */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmt_price(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' zł';
}

function tableExists(PDO $pdo, string $table): bool
{
    $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function columnExists(PDO $pdo, string $table, string $col): bool
{
    $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $q->execute([$table, $col]);
    return (bool)$q->fetchColumn();
}

/** Zwróć wyrażenie kolumny ilości w order_items: qty lub quantity. */
function qtyExprOrderItems(PDO $pdo): string
{
    // Użyj tylko istniejącej kolumny; jeśli żadnej nie ma — zwróć literał 1
    $hasQty  = columnExists($pdo, 'order_items', 'qty');
    $hasQuan = columnExists($pdo, 'order_items', 'quantity');
    if ($hasQty)  return 'oi.qty';
    if ($hasQuan) return 'oi.quantity';
    return '1'; // twardy fallback, żeby nie wywalić SELECT-a
}

function unitPriceExpr(PDO $pdo): string
{
    // Zbuduj wyrażenie wyłącznie z istniejących kolumn
    $hasUnit = columnExists($pdo, 'order_items', 'unit_price');
    $hasPrice = columnExists($pdo, 'order_items', 'price');
    if ($hasUnit && $hasPrice)   return 'COALESCE(oi.unit_price, oi.price)';
    if ($hasUnit)                return 'oi.unit_price';
    if ($hasPrice)               return 'oi.price';
    return '0'; // brak znanych kolumn — nie ryzykuj 1054
}

function totalExprOrderItems(PDO $pdo): string
{
    $qty  = qtyExprOrderItems($pdo);
    // Jeśli istnieje total_price — użyj, inaczej policz qty*unit
    $hasTotal = columnExists($pdo, 'order_items', 'total_price');
    if ($hasTotal) {
        $unit = unitPriceExpr($pdo); // nadal liczymy fallback w razie NULL
        return "COALESCE(oi.total_price, ($qty * $unit))";
    }
    $unit = unitPriceExpr($pdo);
    return "($qty * $unit)";
}

function weightSumExpr(PDO $pdo): ?string
{
    // Zsumuj wagę tylko jeśli mamy kolumnę; wspieramy alternatywy
    $qty = qtyExprOrderItems($pdo);
    $weightCols = ['weight_kg', 'weight', 'item_weight'];
    foreach ($weightCols as $c) {
        if (columnExists($pdo, 'order_items', $c)) {
            return "COALESCE(SUM(oi.`$c` * $qty),0)";
        }
    }
    return null;
}

/** Rodzina klientów (master_client). */
function getLinkedClientIds(PDO $pdo, int $anyClientId): array
{
    if (!columnExists($pdo, 'clients', 'master_client_id')) return [$anyClientId];
    $sql = "SELECT id FROM clients
        WHERE COALESCE(master_client_id, id) = (
          SELECT COALESCE(master_client_id, id) FROM clients WHERE id = ?
        )";
    $st = $pdo->prepare($sql);
    $st->execute([$anyClientId]);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $rows);
    return $ids ?: [$anyClientId];
}
function badgeOrderStatus(string $s): string
{
    $s = strtolower($s);
    $c = 'bg-gray-200 text-gray-800';
    if (str_starts_with($s, 'otwarta_paczka')) $c = 'bg-amber-100 text-amber-900';
    elseif (in_array($s, ['do_wyslania', 'gotowe_do_wysyłki', 'gotowe_do_wysylki'], true)) $c = 'bg-blue-100 text-blue-900';
    elseif (in_array($s, ['zrealizowane', 'wysłane', 'wyslane'], true)) $c = 'bg-green-100 text-green-900';
    elseif (in_array($s, ['anulowane', 'zarchiwizowane'], true)) $c = 'bg-gray-300 text-gray-700';
    return "<span class='px-2 py-0.5 rounded text-xs $c'>" . e($s) . "</span>";
}
function badgePayStatus(?string $s): string
{
    $s = strtolower((string)$s);
    $c = 'bg-gray-200 text-gray-800';
    $t = 'brak płatności';
    if (in_array($s, ['opłacone', 'oplacone', 'opłacone_autopay', 'opłacone_przelewy24', 'opłacone_paynow', 'opłacone_dotpay', 'opłacone_payu', 'opłacone_blik'], true)) {
        $c = 'bg-green-100 text-green-900';
        $t = 'opłacone';
    } elseif ($s === 'rozpoczęta' || $s === 'rozpoczeta') {
        $c = 'bg-blue-100 text-blue-900';
        $t = 'rozpoczęta';
    } elseif ($s === 'błąd' || $s === 'blad') {
        $c = 'bg-red-100 text-red-900';
        $t = 'błąd';
    } elseif ($s === 'oczekujące' || $s === 'oczekujace') {
        $c = 'bg-amber-100 text-amber-900';
        $t = 'oczekujące';
    }
    return "<span class='px-2 py-0.5 rounded text-xs $c'>" . e($t) . "</span>";
}

/** Pobierz kolumny z tabeli (cache-light). */
function fetchExistingColumns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$table]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** UPSERT do client_info z auto-wykrywaniem kolumn (bez HY093). */
function upsertClientInfo(PDO $pdo, array $d): void
{
    $clientId = (int)($d['client_id'] ?? 0);
    $ownerId = (int)($d['owner_id'] ?? 0);
    if ($clientId <= 0 || $ownerId <= 0) return;

    try {
        $pdo->exec("ALTER TABLE client_info ADD UNIQUE KEY uq_client_owner (client_id, owner_id)");
    } catch (Throwable $e) {
    }

    $cols = fetchExistingColumns($pdo, 'client_info');
    $has = array_flip($cols);

    $data = [
        'client_id' => $clientId,
        'owner_id'  => $ownerId,
    ];

    $map = [
        'default_shipping_method_id',
        'default_payment_method_id',
        'default_full_name',
        'default_phone',
        'default_email',
        'default_street',
        'default_postcode',
        'default_city',
        'default_locker_code',
        'default_locker_desc',
    ];
    foreach ($map as $k) {
        if (isset($has[$k]) && array_key_exists($k, $d)) $data[$k] = $d[$k];
    }

    $insertCols = array_keys($data);
    $ph = array_map(fn($c) => ":$c", $insertCols);

    $setUpd = [];
    foreach ($insertCols as $c) {
        if ($c === 'client_id' || $c === 'owner_id') continue;
        $setUpd[] = "$c = VALUES($c)";
    }
    if (isset($has['updated_at'])) $setUpd[] = "updated_at = NOW()";
    $updSql = $setUpd ? " ON DUPLICATE KEY UPDATE " . implode(', ', $setUpd) : "";

    if (isset($has['created_at'])) {
        $insertCols[] = 'created_at';
        $ph[] = 'NOW()';
    }

    $sql = "INSERT INTO client_info (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $ph) . ")$updSql";
    $stmt = $pdo->prepare($sql);

    // Bindy wyłącznie do nazwanych placeholderów (bez created_at/updated_at)
    foreach ($data as $k => $v) $stmt->bindValue(":$k", $v === '' ? null : $v);

    $stmt->execute();
}

/** Pobierz adres do ustawienia (owner-safe) zgodnie ze schematem. */
function fetchAddressForUse(PDO $pdo, int $addrId, int $clientId): ?array
{
    // Czytaj z client_addresses (to z tej tabeli budujemy listę)
    $st = $pdo->prepare("SELECT id,label,full_name,phone,email,street,postal_code,city,locker_code,locker_desc
                       FROM client_addresses
                       WHERE id=:id AND client_id=:cid
                       LIMIT 1");
    $st->execute([':id' => $addrId, ':cid' => $clientId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    // Zmapuj nazwę kolumny postal_code -> 'postcode' na potrzeby dalszego kodu/wyświetlania
    $row['postcode'] = $row['postal_code'] ?? '';
    return $row;
}


/** Buduj warunek „aktywności” dla tabel metod */
function activeWhere(PDO $pdo, string $table, string $alias = ''): string
{
    $alias = $alias ? rtrim($alias, '.') . '.' : '';
    // 1) active=1
    if (columnExists($pdo, $table, 'active')) return " AND {$alias}`active` = 1 ";
    // 2) status in (...)
    if (columnExists($pdo, $table, 'status')) {
        return " AND {$alias}`status` IN ('active','enabled','aktywna','aktywny',1,'1') ";
    }
    // 3) brak kolumny — brak filtra
    return '';
}

/* ──────────────────────────────────────────────────────────────
 * KONTEKST: klient z tokenu
 * ──────────────────────────────────────────────────────────── */
$token = (string)($_GET['token'] ?? $_SESSION['client_token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('Brak tokenu klienta.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("SELECT * FROM clients WHERE token=:t LIMIT 1");
$st->execute([':t' => $token]);
$client = $st->fetch(PDO::FETCH_ASSOC);

/* Jeśli token nie jest client_tokenem — spróbuj checkout/group -> klient */
if (!$client) {
    $group = $resolver->findGroupByToken($pdo, $token);
    if ($group) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$group['order_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order && !empty($order['client_id'])) {
            $client = $clientEngine->getClient((int)$order['client_id']);
        }
    }
}

if (!$client) {
    http_response_code(404);
    exit('Nie znaleziono klienta.');
}

$clientId = (int)$client['id'];
$ownerId = (int)$client['owner_id'];
$_SESSION['owner_id'] = $ownerId;
$_SESSION['client_id'] = $clientId;
$_SESSION['client_token'] = $token;

wlog('moje.view', ['client_id' => $clientId, 'owner_id' => $ownerId]);

if (empty($_SESSION['csrf_moje'])) $_SESSION['csrf_moje'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_moje'];

/* ──────────────────────────────────────────────────────────────
 * LISTY METOD (shipping, payment)
 * ──────────────────────────────────────────────────────────── */
$shippingMethods = [];
if (tableExists($pdo, 'shipping_methods')) {
    $activeSql = activeWhere($pdo, 'shipping_methods', 'sm');
    $pmq = $pdo->prepare("
    SELECT sm.id, sm.name, COALESCE(sm.default_price,0) AS default_price
    FROM shipping_methods sm
    WHERE sm.owner_id = :o
    $activeSql
    ORDER BY sm.name ASC, sm.id ASC
  ");
    $pmq->execute([':o' => $ownerId]);
    $shippingMethods = $pmq->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$paymentMethods = [];
if (tableExists($pdo, 'payment_methods')) {
    $orderPm = columnExists($pdo, 'payment_methods', 'position') ? 'pm.position ASC, pm.id ASC' : 'pm.name ASC, pm.id ASC';
    $activeSql = activeWhere($pdo, 'payment_methods', 'pm');
    $pmq = $pdo->prepare("
    SELECT pm.id, pm.name, COALESCE(pm.type,'') AS type
    FROM payment_methods pm
    WHERE pm.owner_id = :o
    $activeSql
    ORDER BY $orderPm
  ");
    $pmq->execute([':o' => $ownerId]);
    $paymentMethods = $pmq->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ──────────────────────────────────────────────────────────────
 * client_info + domyślny z client_addresses (jeśli jest)
 * ──────────────────────────────────────────────────────────── */
$clientInfo = null;
if (tableExists($pdo, 'client_info')) {
    $ciq = $pdo->prepare("SELECT * FROM client_info WHERE client_id=:cid AND owner_id=:oid LIMIT 1");
    $ciq->execute([':cid' => $clientId, ':oid' => $ownerId]);
    $clientInfo = $ciq->fetch(PDO::FETCH_ASSOC) ?: null;
}

$addrDefault = null;
if (tableExists($pdo, 'client_addresses')) {
    $da = $pdo->prepare("SELECT * FROM client_addresses WHERE client_id=:cid AND owner_id=:oid AND is_default=1 ORDER BY id DESC LIMIT 1");
    $da->execute([':cid' => $clientId, ':oid' => $ownerId]);
    $addrDefault = $da->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($addrDefault) {
    $clientInfo = $clientInfo ?: [];
    $clientInfo['default_full_name'] = $addrDefault['full_name'] ?? ($clientInfo['default_full_name'] ?? '');
    $clientInfo['default_phone']     = $addrDefault['phone'] ?? ($clientInfo['default_phone'] ?? '');
    $clientInfo['default_email']     = $addrDefault['email'] ?? ($clientInfo['default_email'] ?? '');
    if (!empty($addrDefault['locker_code'] ?? '')) {
        $clientInfo['default_locker_code'] = $addrDefault['locker_code'];
        $clientInfo['default_locker_desc'] = $addrDefault['locker_desc'] ?? '';
        $clientInfo['default_street'] = $clientInfo['default_postcode'] = $clientInfo['default_city'] = '';
    } else {
        $clientInfo['default_street']   = $addrDefault['street'] ?? '';
        $clientInfo['default_postcode'] = $addrDefault['postcode'] ?? '';
        $clientInfo['default_city']     = $addrDefault['city'] ?? '';
        $clientInfo['default_locker_code'] = $clientInfo['default_locker_desc'] = '';
    }
}
$displayFullName = trim((string)($clientInfo['default_full_name'] ?? ($client['name'] ?? '')));

/* ──────────────────────────────────────────────────────────────
 * POST: zapisz domyślne
 * ──────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_defaults') {
    if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Błędny CSRF.');
    }

    $default_shipping_method_id = (int)($_POST['default_shipping_method_id'] ?? 0);
    $default_payment_method_id = (int)($_POST['default_payment_method_id'] ?? 0);

    $selName = '';
    foreach ($shippingMethods as $sm) {
        if ((int)$sm['id'] === $default_shipping_method_id) {
            $selName = (string)$sm['name'];
            break;
        }
    }
    $isLocker = (bool)preg_match('/inpost|paczkomat/i', $selName);

    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $street = trim((string)($_POST['street'] ?? ''));
    $postcode = trim((string)($_POST['postcode'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $locker_code = strtoupper(trim((string)($_POST['locker_code'] ?? '')));
    $locker_desc = trim((string)($_POST['locker_desc'] ?? ''));

    $errors = [];
    if ($full_name === '' || $phone === '') $errors[] = 'Podaj imię i nazwisko oraz numer telefonu.';
    if ($isLocker) {
        if ($locker_code === '' || !preg_match('/^[A-Z0-9\-]{4,12}$/', $locker_code)) $errors[] = 'Podaj prawidłowy kod Paczkomatu.';
        $street = $postcode = $city = '';
    } else {
        if ($street === '' || $postcode === '' || $city === '') $errors[] = 'Uzupełnij adres: ulica, kod, miasto.';
        $locker_code = $locker_desc = '';
    }
    if ($default_shipping_method_id && tableExists($pdo, 'shipping_methods')) {
        $chkActive = activeWhere($pdo, 'shipping_methods') === '' ? '' : ' AND ' . trim(activeWhere($pdo, 'shipping_methods'), ' AND ');
        $chk = $pdo->prepare("SELECT COUNT(*) FROM shipping_methods WHERE id=:id AND owner_id=:o $chkActive");
        $chk->execute([':id' => $default_shipping_method_id, ':o' => $ownerId]);
        if (!$chk->fetchColumn()) $errors[] = 'Wybrana metoda dostawy jest niedostępna.';
    }
    if ($default_payment_method_id && tableExists($pdo, 'payment_methods')) {
        $chkActive = activeWhere($pdo, 'payment_methods') === '' ? '' : ' AND ' . trim(activeWhere($pdo, 'payment_methods'), ' AND ');
        $chk = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE id=:id AND owner_id=:o $chkActive");
        $chk->execute([':id' => $default_payment_method_id, ':o' => $ownerId]);
        if (!$chk->fetchColumn()) $errors[] = 'Wybrana metoda płatności jest niedostępna.';
    }

    if ($errors) {
        $_SESSION['moje_error'] = implode(' ', $errors);
        logg('warning', 'account.defaults', 'validation_error', ['client_id' => $clientId, 'errors' => $errors]);
        header('Location: moje.php?token=' . urlencode($token) . '#tab-defaults');
        exit;
    }

    // upsert client_info
    if (tableExists($pdo, 'client_info')) {
        upsertClientInfo($pdo, [
            'client_id' => $clientId,
            'owner_id' => $ownerId,
            'default_shipping_method_id' => $default_shipping_method_id ?: null,
            'default_payment_method_id' => $default_payment_method_id ?: null,
            'default_full_name' => $full_name,
            'default_phone'    => $phone,
            'default_email'    => $email ?: null,
            'default_street'   => $isLocker ? null : $street,
            'default_postcode' => $isLocker ? null : $postcode,
            'default_city'     => $isLocker ? null : $city,
            'default_locker_code' => $isLocker ? $locker_code : null,
            'default_locker_desc' => $isLocker ? ($locker_desc ?: null) : null,
        ]);
    }

    // książka adresowa
    $alsoSaveToBook = isset($_POST['also_save_to_book']) && $_POST['also_save_to_book'] == '1';
    if ($alsoSaveToBook && tableExists($pdo, 'client_addresses')) {
        $pdo->prepare("UPDATE client_addresses SET is_default=0 WHERE client_id=:cid AND owner_id=:oid")->execute([':cid' => $clientId, ':oid' => $ownerId]);
        if ($isLocker) {
            if (columnExists($pdo, 'client_addresses', 'locker_code')) {
                $find = $pdo->prepare("SELECT id FROM client_addresses
                       WHERE client_id=:cid AND owner_id=:oid
                         AND full_name=:fn AND phone=:ph AND COALESCE(email,'')=:em
                         AND street=:st AND postal_code=:pc AND city=:ct
                       LIMIT 1");

                $find->execute([':cid' => $clientId, ':oid' => $ownerId, ':lc' => $locker_code, ':ld' => $locker_desc, ':fn' => $full_name, ':ph' => $phone, ':em' => $email]);
                $addrId = (int)($find->fetchColumn() ?: 0);
                if ($addrId === 0) {
                    $ins = $pdo->prepare("INSERT INTO client_addresses
  (client_id,owner_id,label,full_name,phone,email,street,postal_code,city,is_default,created_at)
  VALUES (:cid,:oid,:label,:fn,:ph,:em,:st,:pc,:ct,1,NOW())");

                    $ins->execute([':cid' => $clientId, ':oid' => $ownerId, ':label' => ('Paczkomat ' . $locker_code), ':fn' => $full_name, ':ph' => $phone, ':em' => $email ?: null, ':lc' => $locker_code, ':ld' => $locker_desc ?: null]);
                } else {
                    $pdo->prepare("UPDATE client_addresses SET is_default=1, updated_at=NOW() WHERE id=:id")->execute([':id' => $addrId]);
                }
            } else {
                $labelTxt = 'Paczkomat ' . $locker_code . ($locker_desc ? ' — ' . $locker_desc : '');
                $find = $pdo->prepare("SELECT id FROM client_addresses
                             WHERE client_id=:cid AND owner_id=:oid
                               AND label=:label AND full_name=:fn AND phone=:ph AND COALESCE(email,'')=:em
                               AND street='' AND postcode='' AND city=''
                             LIMIT 1");
                $find->execute([':cid' => $clientId, ':oid' => $ownerId, ':label' => $labelTxt, ':fn' => $full_name, ':ph' => $phone, ':em' => $email]);
                $addrId = (int)($find->fetchColumn() ?: 0);
                if ($addrId === 0) {
                    $ins = $pdo->prepare("INSERT INTO client_addresses
            (client_id,owner_id,label,full_name,phone,email,street,postcode,city,is_default,created_at)
            VALUES (:cid,:oid,:label,:fn,:ph,:em,'','','',1,NOW())");
                    $ins->execute([':cid' => $clientId, ':oid' => $ownerId, ':label' => $labelTxt, ':fn' => $full_name, ':ph' => $phone, ':em' => $email ?: null]);
                } else {
                    $pdo->prepare("UPDATE client_addresses SET is_default=1, updated_at=NOW() WHERE id=:id")->execute([':id' => $addrId]);
                }
            }
        } else {
            $find = $pdo->prepare("SELECT id FROM client_addresses
                           WHERE client_id=:cid AND owner_id=:oid
                             AND full_name=:fn AND phone=:ph AND COALESCE(email,'')=:em
                             AND street=:st AND postcode=:pc AND city=:ct
                           LIMIT 1");
            $find->execute([':cid' => $clientId, ':oid' => $ownerId, ':fn' => $full_name, ':ph' => $phone, ':em' => $email, ':st' => $street, ':pc' => $postcode, ':ct' => $city]);
            $addrId = (int)($find->fetchColumn() ?: 0);
            if ($addrId === 0) {
                $ins = $pdo->prepare("INSERT INTO client_addresses
          (client_id,owner_id,label,full_name,phone,email,street,postcode,city,is_default,created_at)
          VALUES (:cid,:oid,:label,:fn,:ph,:em,:st,:pc,:ct,1,NOW())");
                $ins->execute([':cid' => $clientId, ':oid' => $ownerId, ':label' => null, ':fn' => $full_name, ':ph' => $phone, ':em' => $email ?: null, ':st' => $street, ':pc' => $postcode, ':ct' => $city]);
            } else {
                $pdo->prepare("UPDATE client_addresses SET is_default=1, updated_at=NOW() WHERE id=:id")->execute([':id' => $addrId]);
            }
        }
    }

    $_SESSION['moje_ok'] = 'Zapisano domyślne dane.';
    logg('info', 'account.defaults', 'save_defaults', [
        'client_id' => $clientId,
        'owner_id' => $ownerId,
        'shipping_method_id' => $default_shipping_method_id,
        'payment_method_id' => $default_payment_method_id
    ]);
    header('Location: moje.php?token=' . urlencode($token) . '#tab-defaults');
    exit;
}

/* ──────────────────────────────────────────────────────────────
 * POST: ustaw domyślne z historii adresów
 * ──────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'use_address') {
    if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Błędny CSRF.');
    }
    $addrId = (int)($_POST['address_id'] ?? 0);
    if ($addrId > 0) {
        $row = fetchAddressForUse($pdo, $addrId, $clientId);
        if ($row) {
            $isLocker = !empty($row['locker_code'] ?? '');
            if (tableExists($pdo, 'client_info')) {
                upsertClientInfo($pdo, [
                    'client_id' => $clientId,
                    'owner_id' => $ownerId,
                    'default_full_name' => $row['full_name'] ?? '',
                    'default_phone' => $row['phone'] ?? '',
                    'default_email' => $row['email'] ?? '',
                    'default_street' => $isLocker ? '' : ($row['street'] ?? ''),
                    'default_postcode' => $isLocker ? '' : ($row['postcode'] ?? ''),
                    'default_city' => $isLocker ? '' : ($row['city'] ?? ''),
                    'default_locker_code' => $row['locker_code'] ?? '',
                    'default_locker_desc' => $row['locker_desc'] ?? '',
                ]);
            }
            $_SESSION['moje_ok'] = 'Ustawiono domyślne dane z wybranego adresu.';
            logg('info', 'account.defaults', 'use_address', ['client_id' => $clientId, 'address_id' => $addrId]);
        }
    }
    header('Location: moje.php?token=' . urlencode($token) . '#tab-addresses');
    exit;
}

/* ──────────────────────────────────────────────────────────────
 * PACZKI + HISTORIA ADRESÓW
 * ──────────────────────────────────────────────────────────── */
$qtyExpr   = qtyExprOrderItems($pdo);
$unitExpr  = unitPriceExpr($pdo);
$totalExpr = totalExprOrderItems($pdo);
$weightExpr = weightSumExpr($pdo);
$linkedIds = getLinkedClientIds($pdo, $clientId);

/* Lista paczek (owner-safe, checkout_token z ORDERS!) */
$groups = [];
if (!empty($linkedIds)) {
    $in = implode(',', array_fill(0, count($linkedIds), '?'));
    $sql = "SELECT 
          og.id,
          og.group_token,
          og.created_at,
          o.id AS order_id,
          o.order_status,
          o.checkout_token,
          COUNT(oi.id) AS items_count,
          COALESCE(SUM($totalExpr),0) AS items_total" .
        ($weightExpr ? ", $weightExpr AS items_weight" : ", NULL AS items_weight") . ",
          (
            SELECT p2.status FROM payments p2 
            WHERE p2.order_group_id = og.id 
            ORDER BY p2.created_at DESC, p2.id DESC LIMIT 1
          ) AS payment_status
        FROM order_groups og
        JOIN orders o ON o.id=og.order_id
        LEFT JOIN order_items oi ON oi.order_group_id=og.id
        WHERE o.client_id IN ($in) AND o.owner_id = ?
        GROUP BY og.id
        ORDER BY og.created_at DESC, og.id DESC";
    $st = $pdo->prepare($sql);
    $i = 1;
    foreach ($linkedIds as $cid) $st->bindValue($i++, $cid, PDO::PARAM_INT);
    $st->bindValue($i, $ownerId, PDO::PARAM_INT);
    $st->execute();
    $groups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* Historia adresów do panelu (jeśli jest tabela) */
$addrHist = [];
if (tableExists($pdo, 'client_addresses')) {
    $qh = $pdo->prepare("SELECT id,label,full_name,phone,email,street,
                              postal_code AS postcode,
                              city,locker_code,locker_desc,is_default,created_at,updated_at
                       FROM client_addresses
                       WHERE client_id=:cid AND owner_id=:oid
                       ORDER BY is_default DESC, updated_at DESC, created_at DESC, id DESC
                       LIMIT 50");
    $qh->execute([':cid' => $clientId, ':oid' => $ownerId]);
    $addrHist = $qh->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/* FLASH */
$ok = $_SESSION['moje_ok'] ?? '';
$err = $_SESSION['moje_error'] ?? '';
unset($_SESSION['moje_ok'], $_SESSION['moje_error']);
?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <title>Moje konto</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-[100dvh]">
    <header class="sticky top-0 z-10 bg-white/90 backdrop-blur border-b">
        <div class="max-w-3xl mx-auto px-4 py-3 flex items-center justify-between">
            <h1 class="text-lg font-bold">👤 Moje konto</h1>
            <div class="flex items-center gap-4 text-sm">
                <a href="/" class="text-indigo-700 underline">Sklep</a>
                <form method="post" action="/konto/logout.php" onsubmit="return confirm('Czy na pewno chcesz się wylogować?')">
                    <button type="submit" class="text-red-600 underline">Wyloguj</button>
                </form>
            </div>
        </div>
    </header>

    <main class="max-w-3xl mx-auto p-4">
        <?php if ($ok): ?>
            <div class="mb-3 p-3 rounded bg-emerald-100 text-emerald-800 border border-emerald-300 text-sm"><?= e($ok) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="mb-3 p-3 rounded bg-red-100 text-red-800 border border-red-300 text-sm"><?= e($err) ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="flex overflow-x-auto gap-2 mb-3">
            <button class="tab-btn px-3 py-2 rounded-lg bg-indigo-600 text-white shrink-0" data-tab="orders">Paczki</button>
            <button class="tab-btn px-3 py-2 rounded-lg bg-white border shrink-0" data-tab="profile">Dane & adres</button>
            <button class="tab-btn px-3 py-2 rounded-lg bg-white border shrink-0" data-tab="defaults" id="tab-defaults">Domyślne metody</button>
            <button class="tab-btn px-3 py-2 rounded-lg bg-white border shrink-0" data-tab="addresses" id="tab-addresses">Historia adresów</button>
        </div>

        <!-- PANEL: Paczki -->
        <section id="panel-orders" class="card bg-white rounded-2xl shadow p-4">
            <h2 class="text-lg font-semibold mb-3">📦 Twoje paczki</h2>
            <?php if (empty($groups)): ?>
                <div class="text-sm text-gray-600">Brak paczek do wyświetlenia.</div>
            <?php else: ?>
                <ul class="divide-y">
                    <?php foreach ($groups as $g): ?>
                        <li class="py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-medium truncate">
                                    Zamówienie #<?= (int)$g['order_id'] ?> • Paczka z <?= e(date('d.m.Y H:i', strtotime($g['created_at']))) ?>
                                </div>
                                <div class="text-xs text-gray-600 flex items-center gap-2 mt-0.5">
                                    <?= badgeOrderStatus((string)$g['order_status']) ?>
                                    <?= badgePayStatus($g['payment_status'] ?? null) ?>
                                    <span>•</span>
                                    <span><?= (int)$g['items_count'] ?> poz.</span>
                                    <?php if (!empty($g['items_weight']) && (float)$g['items_weight'] > 0): ?>
                                        <span>•</span>
                                        <span>~<?= number_format((float)$g['items_weight'], 2, ',', ' ') ?> kg</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (tableExists($pdo, 'checkout_access_log')): ?>
                                    <div class="mt-1 text-[11px] text-gray-500 flex gap-2 flex-wrap">
                                        <?php
                                        $tl = $pdo->prepare("SELECT step, created_at FROM checkout_access_log WHERE order_group_id=? ORDER BY id DESC LIMIT 3");
                                        $tl->execute([(int)$g['id']]);
                                        $timeline = $tl->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                        foreach ($timeline as $ev): ?>
                                            <span class="px-1.5 py-0.5 bg-gray-100 rounded">
                                                <?= e((string)($ev['step'] ?? 'wejście')) ?> · <?= e(date('d.m H:i', strtotime($ev['created_at']))) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="text-sm font-semibold"><?= fmt_price((float)$g['items_total']) ?></div>
                                <div class="flex items-center gap-2 justify-end mt-1">
                                    <?php if (!empty($g['checkout_token'])): ?>
                                        <a class="text-xs text-indigo-700 underline" target="_blank" rel="nofollow"
                                            href="/checkout/thank_you.php?token=<?= urlencode((string)$g['checkout_token']) ?>">
                                            Otwórz
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">brak tokenu</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- PANEL: Dane & adres -->
        <section id="panel-profile" class="card bg-white rounded-2xl shadow p-4 mt-3 hidden">
            <h2 class="text-lg font-semibold mb-3">👤 Dane kontaktowe</h2>
            <div class="text-sm grid gap-1">
                <div><span class="text-gray-500">Imię i nazwisko:</span> <?= e($displayFullName) ?></div>
                <div><span class="text-gray-500">Telefon:</span> <?= e($clientInfo['default_phone'] ?? ($client['phone'] ?? '')) ?></div>
                <div><span class="text-gray-500">E-mail:</span> <?= e($clientInfo['default_email'] ?? ($client['email'] ?? '')) ?></div>
            </div>

            <h3 class="text-md font-semibold mt-4 mb-2">📮 Domyślny adres</h3>
            <?php $isLockerView = !empty($clientInfo['default_locker_code'] ?? ''); ?>
            <?php if ($isLockerView): ?>
                <div class="text-sm">
                    Paczkomat: <strong><?= e($clientInfo['default_locker_code']) ?></strong>
                    <?php if (!empty($clientInfo['default_locker_desc'])): ?> – <?= e($clientInfo['default_locker_desc']) ?><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-sm">
                    <?= e($clientInfo['default_street'] ?? '') ?><br>
                    <?= e($clientInfo['default_postcode'] ?? '') ?> <?= e($clientInfo['default_city'] ?? '') ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- PANEL: Domyślne metody & formularz -->
        <section id="panel-defaults" class="card bg-white rounded-2xl shadow p-4 mt-3 hidden">
            <h2 class="text-lg font-semibold mb-3">⚙️ Domyślne metody & dane</h2>
            <?php if (empty($shippingMethods) || empty($paymentMethods)): ?>
                <div class="mb-3 p-3 rounded bg-amber-50 text-amber-800 border border-amber-200 text-sm">
                    Brak aktywnych metod dostawy lub płatności u sprzedawcy.
                </div>
            <?php endif; ?>
            <form method="post" class="grid gap-3" id="defaultsForm">
                <input type="hidden" name="action" value="save_defaults">
                <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">

                <div class="grid sm:grid-cols-2 gap-2">
                    <label class="text-sm">
                        <div class="text-gray-600 mb-1">Metoda dostawy</div>
                        <select name="default_shipping_method_id" class="w-full border rounded px-3 py-2" id="smSelect" <?= empty($shippingMethods) ? 'disabled' : '' ?> required>
                            <option value="">— wybierz —</option>
                            <?php $selSm = (int)($clientInfo['default_shipping_method_id'] ?? 0);
                            foreach ($shippingMethods as $sm): ?>
                                <option value="<?= (int)$sm['id'] ?>" <?= $selSm === (int)$sm['id'] ? 'selected' : '' ?>>
                                    <?= e($sm['name']) ?> (<?= fmt_price((float)$sm['default_price']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="text-sm">
                        <div class="text-gray-600 mb-1">Metoda płatności</div>
                        <select name="default_payment_method_id" class="w-full border rounded px-3 py-2" <?= empty($paymentMethods) ? 'disabled' : '' ?> required>
                            <option value="">— wybierz —</option>
                            <?php $selPm = (int)($clientInfo['default_payment_method_id'] ?? 0);
                            foreach ($paymentMethods as $pm): ?>
                                <option value="<?= (int)$pm['id'] ?>" <?= $selPm === (int)$pm['id'] ? 'selected' : '' ?>>
                                    <?= e($pm['name']) ?><?= $pm['type'] ? ' • ' . e($pm['type']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="grid sm:grid-cols-2 gap-2">
                    <input name="full_name" class="border rounded px-3 py-2" placeholder="Imię i nazwisko" autocomplete="name"
                        value="<?= e($clientInfo['default_full_name'] ?? $displayFullName) ?>" required>
                    <input name="phone" class="border rounded px-3 py-2" placeholder="Telefon" inputmode="tel" pattern="[\d +()-]{6,}" autocomplete="tel"
                        value="<?= e($clientInfo['default_phone'] ?? ($client['phone'] ?? '')) ?>" required>
                </div>
                <input name="email" type="email" class="border rounded px-3 py-2" placeholder="E-mail (opcjonalnie)" autocomplete="email"
                    value="<?= e($clientInfo['default_email'] ?? ($client['email'] ?? '')) ?>">

                <!-- Adres -->
                <div id="addrFields" class="grid gap-2">
                    <input name="street" class="border rounded px-3 py-2" placeholder="Ulica i nr" autocomplete="address-line1"
                        value="<?= e($clientInfo['default_street'] ?? '') ?>">
                    <div class="grid grid-cols-2 gap-2">
                        <input name="postcode" class="border rounded px-3 py-2" placeholder="Kod (np. 00-001)"
                            inputmode="numeric" pattern="^\d{2}-\d{3}$" autocomplete="postal-code"
                            value="<?= e($clientInfo['default_postcode'] ?? '') ?>">
                        <input name="city" class="border rounded px-3 py-2" placeholder="Miasto" autocomplete="address-level2"
                            value="<?= e($clientInfo['default_city'] ?? '') ?>">
                    </div>
                </div>

                <!-- Paczkomat -->
                <div id="lockerFields" class="grid gap-2 hidden">
                    <input name="locker_code" id="locker_code" class="border rounded px-3 py-2 uppercase" placeholder="Kod Paczkomatu (np. WAW01A)"
                        value="<?= e($clientInfo['default_locker_code'] ?? '') ?>">
                    <input name="locker_desc" class="border rounded px-3 py-2" placeholder="Opis lokalizacji (opcjonalnie)"
                        value="<?= e($clientInfo['default_locker_desc'] ?? '') ?>">
                </div>

                <label class="flex items-start gap-2 text-sm mt-1">
                    <input type="checkbox" name="also_save_to_book" value="1" class="mt-1" checked>
                    <span>Zapisz te dane też w mojej książce adresowej i ustaw jako domyślne</span>
                </label>

                <button class="mt-2 bg-indigo-600 text-white rounded px-4 py-2" <?= (empty($shippingMethods) || empty($paymentMethods)) ? 'disabled' : '' ?>>Zapisz</button>
            </form>
        </section>

        <!-- PANEL: Historia adresów -->
        <section id="panel-addresses" class="card bg-white rounded-2xl shadow p-4 mt-3 hidden">
            <h2 class="text-lg font-semibold mb-3">🗂️ Historia adresów</h2>
            <?php if (empty($addrHist)): ?>
                <div class="text-sm text-gray-600">Brak zapisanych adresów.</div>
            <?php else: ?>
                <ul class="divide-y">
                    <?php foreach ($addrHist as $a): $isLocker = !empty($a['locker_code']); ?>
                        <li class="py-3 flex items-start justify-between gap-3">
                            <div class="text-sm">
                                <div class="font-medium">
                                    <?= e($a['full_name'] ?? '') ?> <?= $isLocker ? '• Paczkomat' : '' ?>
                                    <?php if (!empty($a['is_default'])): ?><span class="ml-2 text-[11px] px-1.5 py-0.5 bg-emerald-100 text-emerald-800 rounded">domyślny</span><?php endif; ?>
                                </div>
                                <div class="text-gray-600">
                                    <?php if ($isLocker): ?>
                                        <?= e($a['locker_code']) ?><?php if (!empty($a['locker_desc'])): ?> — <?= e($a['locker_desc']) ?><?php endif; ?>
                                    <?php else: ?>
                                        <?= e($a['street'] ?? '') ?><br>
                                        <?= e($a['postcode'] ?? '') ?> <?= e($a['city'] ?? '') ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($a['phone'])): ?>
                                    <div class="text-xs text-gray-500 mt-0.5">tel: <?= e($a['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="shrink-0">
                                <input type="hidden" name="action" value="use_address">
                                <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
                                <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                                <button class="text-xs bg-blue-600 text-white px-3 py-1 rounded">Ustaw jako domyślny</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    </main>

    <script>
        // Tabs
        const tabs = document.querySelectorAll('.tab-btn');
        const panels = {
            orders: document.getElementById('panel-orders'),
            profile: document.getElementById('panel-profile'),
            defaults: document.getElementById('panel-defaults'),
            addresses: document.getElementById('panel-addresses')
        };

        function activate(tab) {
            tabs.forEach(b => {
                b.classList.toggle('bg-indigo-600', b.dataset.tab === tab);
                b.classList.toggle('text-white', b.dataset.tab === tab);
                b.classList.toggle('bg-white', b.dataset.tab !== tab);
                b.classList.toggle('border', b.dataset.tab !== tab);
            });
            Object.keys(panels).forEach(k => panels[k].classList.toggle('hidden', k !== tab));
        }
        tabs.forEach(b => b.addEventListener('click', () => activate(b.dataset.tab)));
        const hash = (location.hash || '').replace('#tab-', '');
        if (hash && panels[hash]) activate(hash);
        else activate('orders');

        // Locker auto-toggle
        function isLockerName(name) {
            return /inpost|paczkomat/i.test(name || '');
        }

        function toggleLockerBySelect() {
            const sel = document.getElementById('smSelect');
            const name = sel ? sel.options[sel.selectedIndex]?.text : '';
            const locker = isLockerName(name);
            const addr = document.getElementById('addrFields');
            const lock = document.getElementById('lockerFields');
            if (!addr || !lock) return;
            addr.classList.toggle('hidden', locker);
            lock.classList.toggle('hidden', !locker);
            const st = document.querySelector('input[name="street"]');
            const pc = document.querySelector('input[name="postcode"]');
            const ct = document.querySelector('input[name="city"]');
            if (st && pc && ct) {
                st.required = !locker;
                pc.required = !locker;
                ct.required = !locker;
            }
            const lc = document.getElementById('locker_code');
            if (lc) lc.required = locker;
        }
        const smSel = document.getElementById('smSelect');
        if (smSel) smSel.addEventListener('change', toggleLockerBySelect);
        toggleLockerBySelect();

        // Uppercase + filtr znaków
        const lockerCodeEl = document.getElementById('locker_code');
        if (lockerCodeEl) lockerCodeEl.addEventListener('input', () => {
            lockerCodeEl.value = lockerCodeEl.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
        });
    </script>
</body>

</html>