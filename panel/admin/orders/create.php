<?php
// admin/orders/create.php — Olaj.pl V4 (engine-first + heavy logging + schema diag)
// Data: 2025-09-06
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

// 🔥 Centralny logger (olaj_v4_logger) — wymaga engine/Log/LogEngine.php, wpięty w includes/log.php


use Engine\Orders\OrderEngine;
use Engine\Orders\ProductEngine;

// ─────────────────────────────────────────────────────────────────────────────
// Sesja + Owner
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(403);
    echo "Brak owner_id. Zaloguj się ponownie.";
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF
// ─────────────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_orders_create'])) {
    $_SESSION['csrf_orders_create'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_orders_create'];

// ─────────────────────────────────────────────────────────────────────────────
// Diagnoza schematu (tabele/kolumny krytyczne dla tej strony)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Zwraca listę braków w stylu:
 * [
 *   ['table'=>'orders','issue'=>'missing_table'],
 *   ['table'=>'order_groups','column'=>'checkout_token','issue'=>'missing_column'],
 *   ...
 * ]
 */
function diagnoseSchema(PDO $pdo, int $ownerId): array
{
    $mustTables = [
        'clients'       => [],
        'orders'        => ['owner_id','client_id','order_status','checkout_completed','created_at'],
        'order_groups'  => ['order_id','group_token','paid_status','created_at'],

        'order_items'   => ['owner_id','order_id','order_group_id','name','qty','unit_price','vat_rate','source_type','created_at'],
        // opcjonalne, ale mile widziane:
        'products'      => ['owner_id','code','sku','ean','twelve_nc','name','unit_price','vat_rate'],
        'logs'          => ['owner_id','level','channel','message','context_json','created_at'],
    ];

    $issues = [];

    // helpery
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $hasTable = static function(PDO $pdo, string $db, string $table): bool {
        $q = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=:s AND table_name=:t");
        $q->execute([':s'=>$db, ':t'=>$table]);
        return (bool)$q->fetchColumn();
    };
    $hasColumn = static function(PDO $pdo, string $db, string $table, string $col): bool {
        $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=:s AND table_name=:t AND column_name=:c");
        $q->execute([':s'=>$db, ':t'=>$table, ':c'=>$col]);
        return (bool)$q->fetchColumn();
    };

    foreach ($mustTables as $t => $cols) {
        if (!$hasTable($pdo, $dbName, $t)) {
            $issues[] = ['table'=>$t, 'issue'=>'missing_table'];
            continue;
        }
        foreach ($cols as $c) {
            if (!$hasColumn($pdo, $dbName, $t, $c)) {
                $issues[] = ['table'=>$t, 'column'=>$c, 'issue'=>'missing_column'];
            }
        }
    }

    // dodatkowa sanity-check: ENUM / kolumnowe niespodzianki
    // Nie wchodzimy w wartości — tylko istnienie, żeby nie robić fałszywych alarmów.

    // spróbuj prostego SELECT-a owner-scoped — wykrywa np. brak owner_id w kluczowych tabelach.
    foreach (['clients','orders','order_groups','order_items'] as $t) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE owner_id=:oid LIMIT 1");
            $stmt->execute([':oid'=>$ownerId]);
            // jeśli kolumny brak — poleci w Exception, złapiemy poniżej
        } catch (Throwable $e) {
            $issues[] = ['table'=>$t, 'issue'=>'owner_scope_broken', 'hint'=>$e->getMessage()];
        }
    }

    return $issues;
}

// uruchom diagnozę i ZALOGUJ (żeby było w historii)
$schemaIssues = [];
try {
    $schemaIssues = diagnoseSchema($pdo, $ownerId);
    if ($schemaIssues) {
        logg('warning', 'orders.create.schema', 'Schema issues detected', [
            'owner_id'=>$ownerId,
            'issues'=>$schemaIssues,
        ]);
    } else {
        logg('info', 'orders.create.schema', 'Schema OK for create.php', ['owner_id'=>$ownerId]);
    }
} catch (Throwable $e) {
    logg('error', 'orders.create.schema', 'Schema diagnosis failed', [
        'owner_id'=>$ownerId,
        'err'=>$e->getMessage(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — Engine-first z fallbackiem
// ─────────────────────────────────────────────────────────────────────────────
function createOrderAndGroup(PDO $pdo, int $ownerId, int $clientId, int $userId): array
{
    $t0 = microtime(true);
    // 1) Próbujemy OrderEngine
    try {
        if (class_exists(OrderEngine::class)) {
            $oe = new OrderEngine($pdo);
            $orderId = 0;
            if (method_exists($oe, 'findOrCreateOpenOrderForClient')) {
                $o = $oe->findOrCreateOpenOrderForClient($ownerId, $clientId, 'panel');
                $orderId = (int)($o['id'] ?? 0);
            } elseif (method_exists($oe, 'ensureOpenOrder')) {
                $o = $oe->ensureOpenOrder($ownerId, $clientId, 'panel');
                $orderId = (int)($o['id'] ?? 0);
            }
            if ($orderId > 0) {
                $groupId = 0; $token = '';
                if (method_exists($oe, 'findOrCreateOpenGroup')) {
                    $g = $oe->findOrCreateOpenGroup($orderId);
                    $groupId = (int)($g['id'] ?? 0);
                    $token   = (string)($g['checkout_token'] ?? $g['group_token'] ?? '');
                } elseif (method_exists($oe, 'createGroupForOrder')) {
                    $g = $oe->createGroupForOrder($orderId, options: ['source' => 'panel', 'actor_id'=>$userId]);
                    $groupId = (int)($g['id'] ?? 0);
                    $token   = (string)($g['checkout_token'] ?? $g['group_token'] ?? '');
                }
                if ($groupId > 0) {
                    logg('info', 'orders.create', 'Order+Group created via OrderEngine', [
                        'owner_id'=>$ownerId,'client_id'=>$clientId,'user_id'=>$userId,
                        'order_id'=>$orderId,'group_id'=>$groupId,'checkout_token'=>$token,
                        'ms'=>round((microtime(true)-$t0)*1000,1),
                    ]);
                    return [$orderId, $groupId, $token];
                }
            }
        }
    } catch (Throwable $e) {
        logg('error', 'orders.create', 'OrderEngine path failed', [
            'owner_id'=>$ownerId,'client_id'=>$clientId,'user_id'=>$userId,'err'=>$e->getMessage()
        ]);
    }

    // 2) Fallback SQL
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO orders (owner_id, client_id, order_status, checkout_completed, created_at, updated_at)
            VALUES (:owner_id, :client_id, 'otwarta_paczka:add_products', 0, NOW(), NOW())
        ");
        $stmt->execute([':owner_id'=>$ownerId, ':client_id'=>$clientId]);
        $orderId = (int)$pdo->lastInsertId();

        $token = 'chk-'.bin2hex(random_bytes(8));
        $stmt = $pdo->prepare("
            INSERT INTO order_groups (owner_id, order_id, checkout_token, paid_status, created_at, updated_at, created_by)
            VALUES (:owner_id, :order_id, :tok, 'nieopłacona', NOW(), NOW(), :uid)
        ");
        $stmt->execute([':owner_id'=>$ownerId, ':order_id'=>$orderId, ':tok'=>$token, ':uid'=>$userId]);
        $groupId = (int)$pdo->lastInsertId();

        $pdo->commit();

        logg('warning', 'orders.create', 'Order+Group created via SQL fallback', [
            'owner_id'=>$ownerId,'client_id'=>$clientId,'user_id'=>$userId,
            'order_id'=>$orderId,'group_id'=>$groupId,'checkout_token'=>$token,
            'ms'=>round((microtime(true)-$t0)*1000,1),
        ]);

        return [$orderId, $groupId, $token];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logg('error', 'orders.create', 'SQL fallback failed', [
            'owner_id'=>$ownerId,'client_id'=>$clientId,'user_id'=>$userId,'err'=>$e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Szybkie dodanie pozycji przez „Daj …”
 * 1) OrderEngine::addOrderItemByCode() (jeśli jest)
 * 2) lookup w products po code/sku/ean/12nc
 * 3) fallback: custom pozycja (0 zł, 23% VAT)
 */
function quickAddByDaj(PDO $pdo, int $ownerId, int $orderId, int $groupId, string $daj, int $userId): void
{
    $orig = $daj = trim($daj);
    if ($daj === '') return;

    $qty = 1.0;
    // wyłap „x2”, „+3”, „ *1.5”
    if (preg_match('/\b([+x*]?\s*\d+(?:[.,]\d+)?)\b/iu', $daj, $m)) {
        $raw = str_replace(',', '.', $m[1]);
        $raw = ltrim($raw, '+x* ');
        $qty = max(0.001, (float)$raw);
        $daj = trim(str_ireplace($m[0], '', $daj));
    }
    $code = preg_replace('/^\s*daj\s+/iu', '', $daj);
    $code = trim($code);

    if ($code === '') return;

    try {
        // 1) Engine direct
        if (class_exists(OrderEngine::class)) {
            $oe = new OrderEngine($pdo);
            if (method_exists($oe, 'addOrderItemByCode')) {
                $oe->addOrderItemByCode($ownerId, $orderId, $groupId, $code, $qty, source:'panel', actorId:$userId);
                logg('info','orders.create.daj','addOrderItemByCode OK', compact('ownerId','orderId','groupId','code','qty','userId'));
                return;
            }
        }

        // 2) Lookup product
        $p = null;
        try {
            // prefer ProductEngine, jeśli ma helper
            if (class_exists(ProductEngine::class)) {
                $pe = new ProductEngine($pdo);
                if (method_exists($pe, 'findByAnyCode')) {
                    $p = $pe->findByAnyCode($ownerId, $code);
                }
            }
        } catch (Throwable $e) {
            logg('warning','orders.create.daj','ProductEngine lookup failed',['err'=>$e->getMessage(),'code'=>$code]);
        }
        if (!$p) {
            $q = $pdo->prepare("
                SELECT id,name,unit_price,vat_rate,sku FROM products
                WHERE owner_id=:oid AND deleted_at IS NULL
                 AND (code=:c1 OR sku=:c2 OR ean=:c3 OR twelve_nc=:c4 OR name=:c5)

                LIMIT 1
            ");
           $q->execute([':oid'=>$ownerId, ':c1'=>$code, ':c2'=>$code, ':c3'=>$code, ':c4'=>$code, ':c5'=>$code]);

            $p = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($p && class_exists(OrderEngine::class) && method_exists((new OrderEngine($pdo)), 'addOrderItem')) {
            $oe = new OrderEngine($pdo);
            $oe->addOrderItem(
                $ownerId, $orderId, $groupId,
                (int)$p['id'], (string)$p['name'],
                (float)$qty, (float)($p['unit_price'] ?? 0), (float)($p['vat_rate'] ?? 23),
                sku: (string)($p['sku'] ?? ''), source: 'panel', actorId:$userId
            );
            logg('info','orders.create.daj','addOrderItem (catalog) OK', ['product_id'=>$p['id'],'qty'=>$qty]);
            return;
        }

        // 3) Fallback: custom item
        $ins = $pdo->prepare("
            INSERT INTO order_items (owner_id, order_id, order_group_id, name, qty, unit_price, vat_rate, source_type, created_by, created_at)
            VALUES (:oid,:oidr,:gid,:name,:qty,0,23,'panel',:uid,NOW())
        ");
        $ins->execute([
            ':oid'=>$ownerId, ':oidr'=>$orderId, ':gid'=>$groupId,
            ':name'=>$code, ':qty'=>$qty, ':uid'=>$userId
        ]);
        logg('warning','orders.create.daj','fallback custom item inserted', ['name'=>$code,'qty'=>$qty]);

    } catch (Throwable $e) {
        logg('error','orders.create.daj','quickAddByDaj failed', [
            'owner_id'=>$ownerId,'order_id'=>$orderId,'group_id'=>$groupId,
            'orig'=>$orig,'err'=>$e->getMessage(),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    $clientId   = (int)($_POST['client_id'] ?? 0);
    $daj        = (string)($_POST['quick_daj'] ?? '');

    logg('info','orders.create.http','POST begin', [
        'owner_id'=>$ownerId,'user_id'=>$userId,'client_id'=>$clientId,'has_daj'=>$daj !== ''
    ]);

    if (!$postedCsrf || !hash_equals($CSRF, $postedCsrf)) {
        $errors[] = 'Nieprawidłowy token bezpieczeństwa (CSRF).';
        logg('warning','orders.create.http','CSRF mismatch', ['expected'=>$CSRF,'got'=>$postedCsrf ? 'present':'empty']);
    }

    // istnieje klient owner-scoped?
    if (!$errors) {
        try {
            $c = $pdo->prepare("SELECT id FROM clients WHERE id=:id AND owner_id=:oid AND deleted_at IS NULL");
            $c->execute([':id'=>$clientId, ':oid'=>$ownerId]);
            if (!$c->fetchColumn()) {
                $errors[] = 'Nie znaleziono klienta dla tego właściciela.';
                logg('warning','orders.create.http','client not found', ['client_id'=>$clientId,'owner_id'=>$ownerId]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Błąd weryfikacji klienta: '.$e->getMessage();
            logg('error','orders.create.http','client check failed', ['err'=>$e->getMessage()]);
        }
    }

    if (!$errors) {
        try {
            [$orderId, $groupId, $checkoutToken] = createOrderAndGroup($pdo, $ownerId, $clientId, $userId);
            if ($daj !== '') {
                quickAddByDaj($pdo, $ownerId, $orderId, $groupId, $daj, $userId);
            }
            logg('info','orders.create.done','redirect to view', [
                'order_id'=>$orderId,'group_id'=>$groupId,'checkout_token'=>$checkoutToken
            ]);
            header('Location: /admin/orders/view.php?id='.(int)$orderId);
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Błąd tworzenia zamówienia: '.htmlspecialchars($e->getMessage());
            logg('error','orders.create.done','creation failed', ['err'=>$e->getMessage()]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Widok
// ─────────────────────────────────────────────────────────────────────────────
$pageTitle = "Nowe zamówienie";
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">➕ <?= htmlspecialchars($pageTitle) ?></h1>

    <?php if (!empty($schemaIssues)): ?>
        <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">
            <div class="font-semibold mb-1">Diagnostyka schematu wykryła problemy:</div>
            <ul class="list-disc pl-5 text-sm">
                <?php foreach ($schemaIssues as $iss): ?>
                    <li>
                        <?= htmlspecialchars($iss['table']) ?> —
                        <?php if (($iss['issue'] ?? '') === 'missing_table'): ?>
                            <b>brak tabeli</b>
                        <?php elseif (($iss['issue'] ?? '') === 'missing_column'): ?>
                            brak kolumny <code><?= htmlspecialchars($iss['column'] ?? '') ?></code>
                        <?php elseif (($iss['issue'] ?? '') === 'owner_scope_broken'): ?>
                            problem z owner_scope (<?= htmlspecialchars($iss['hint'] ?? 'unknown') ?>)
                        <?php else: ?>
                            <?= htmlspecialchars($iss['issue'] ?? 'issue') ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs mt-2">Szczegóły zapisane w logach kanału <code>orders.create.schema</code>.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mb-4 rounded-xl border border-red-300 bg-red-50 p-4 text-red-700">
            <ul class="list-disc pl-5">
                <?php foreach ($errors as $e): ?>
                    <li><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="text-xs mt-2">Błąd również zalogowano w <code>orders.create.*</code>.</p>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-6" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>"/>

        <div>
            <label class="block text-sm font-medium mb-1">Klient</label>
            <select id="client_id" name="client_id" class="w-full border rounded-xl p-2" required></select>
            <p class="text-xs text-gray-500 mt-1">Wyszukaj po imieniu, e-mailu lub telefonie (owner-scoped).</p>
        </div>



<div class="flex items-center gap-2 w-full mb-3">
  <select id="product_search" class="flex-1" placeholder="Wyszukaj produkt…"></select>
  <input type="number" id="product_qty" min="1" step="1" value="1" class="w-20 border rounded p-1 text-right" title="Ilość">
  <input type="text" id="product_price" class="w-24 border rounded p-1 text-right" placeholder="cena" title="Cena">
  <button type="button" id="add_product_btn" class="px-3 py-1 bg-blue-600 text-white rounded">Dodaj</button>
</div>








        <div class="flex items-center gap-3">
            <button type="submit" class="px-4 py-2 rounded-xl bg-black text-white hover:bg-gray-800">Utwórz zamówienie</button>
            <a href="/admin/orders/index.php" class="px-4 py-2 rounded-xl border hover:bg-gray-50">Anuluj</a>
        </div>
    </form>
</div>
<script>
// Tymczasowy test — tworzy dummy select obok
jQuery(function($){
  var $t = $('<select id="dummy_test" multiple></select>').appendTo('body');
  $t.append('<option value="1">Test A</option><option value="2">Test B</option>');
  $t.select2({ width:'100%' });
});
</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>


<script>

jQuery(function ($) {
  var $el = $('#client_id');
  if (!$el.length) {
    console.error('#client_id nie istnieje w DOM!');
    return;
  }
  // Wymuś szerokość
  $el.css('width','100%');

  // Minimalna konfiguracja + twardy format odpowiedzi
  $el.select2({
    width: '100%',
    placeholder: 'Wybierz klienta…',
    minimumInputLength: 2,
    ajax: {
      delay: 200,
      url: '/admin/clients/api/search.php',
      dataType: 'json',
      transport: function (params, success, failure) {
        // DIAG: log request/response
        var req = $.ajax(params);
        req.then(function (data) { console.log('select2 AJAX OK', data); success(data); })
           .fail(function (xhr) { console.error('select2 AJAX FAIL', xhr.status, xhr.responseText); failure(); });
        return req;
      },
      data: function (params) { return { q: params.term || '' }; },
      processResults: function (data) {
        // oczekujemy {items:[{id,text},...]}
        var items = Array.isArray(data) ? data : (data.items || []);
        return { results: items };
      }
    }
  });
});
</script>

<script>

// Inicjalizacja dopiero kiedy jQuery+Select2 już są
jQuery(function($){
  if (!$.fn.select2) {
    console.error('Select2 nie jest załadowany. Sprawdź kolejność <script>.');
    return;
  }

  // Klient (Select2)
  $('#client_id').select2({
    width: '100%',
    placeholder: 'Wybierz klienta…',
    minimumInputLength: 2,
    ajax: {
      delay: 200,
      url: '/admin/clients/api/search.php',
      dataType: 'json',
      data: params => ({ q: params.term || '' }),
      processResults: data => ({ results: (data && data.items) ? data.items : [] })
    }
  });

  // Produkty (Select2)
  $('#product_search').select2({
    width: '100%',
    placeholder: 'Nazwa, kod, SKU, EAN…',
    minimumInputLength: 2,
    ajax: {
      url: '/admin/products/api/search.php',
      dataType: 'json',
      delay: 200,
      data: params => ({ q: params.term || '', limit: 20 }),
      processResults: data => ({ results: (data && data.items) ? data.items : [] })
    },
    templateResult: item => {
      if (!item.id) return item.text;
      const sku   = item.sku ? ` [${item.sku}]` : '';
      const price = (item.unit_price != null) ? ` — ${Number(item.unit_price).toFixed(2)} zł` : '';
      return $(`<span>${item.name || item.text}${sku}${price}</span>`);
    },
    templateSelection: item => item.text || (item.name || '')
  });

  // Uzupełnia cenę po wyborze
  $('#product_search').on('select2:select', function(e){
    const p = e.params.data;
    if (p && p.unit_price != null) {
      $('#product_price').val(Number(p.unit_price).toFixed(2));
    }
  });

  // Dodanie pozycji (z pre-create jeśli trzeba)
  async function ensureOrderAndGroup(clientId){
    const fd = new FormData();
    fd.append('client_id', String(clientId));
    const r = await fetch('/admin/orders/api/create_or_get.php', { method:'POST', body:fd, credentials:'same-origin' });
    const j = await r.json();
    if (!j || !j.ok) throw new Error((j && (j.message||j.reason)) || 'create_or_get failed');
    return { orderId: j.order_id, groupId: j.group_id };
  }

  async function addSelectedProduct(){
    const sel = $('#product_search').select2('data')[0];
    if (!sel || !sel.id) { alert('Wybierz produkt.'); return; }
    const clientId = parseInt($('#client_id').val() || '0', 10);
    if (!clientId) { alert('Najpierw wybierz klienta.'); return; }

    const qty   = Math.max(1, parseFloat($('#product_qty').val() || 1));
    const price = $('#product_price').val() === '' ? null : parseFloat($('#product_price').val());

    try {
      const { orderId, groupId } = await ensureOrderAndGroup(clientId);

      const fd = new FormData();
      fd.append('order_id',  String(orderId));
      fd.append('group_id',  String(groupId));
      fd.append('product_id', String(sel.id));
      fd.append('qty',       String(qty));
      if (price !== null && !Number.isNaN(price)) fd.append('unit_price', String(price));

      const res = await fetch('/admin/orders/api/add_item.php', { method:'POST', body:fd, credentials:'same-origin' });
      const j = await res.json();
      if (!j || !j.ok) throw new Error((j && (j.message||j.reason)) || 'add_item failed');

      // reset i przejście do widoku zamówienia
      $('#product_search').val(null).trigger('change');
      $('#product_qty').val(1);
      $('#product_price').val('');
      location.href = '/admin/orders/view.php?id=' + encodeURIComponent(orderId);
    } catch (e) {
      alert('Nie udało się dodać pozycji: ' + e.message);
      console.error(e);
    }
  }

  $('#add_product_btn').on('click', addSelectedProduct);
  $('#product_search').on('select2:select', addSelectedProduct);
});
</script>
<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>
