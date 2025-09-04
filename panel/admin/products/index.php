<?php
// admin/products/index.php — Olaj V4: Products (full, engine-first)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php'; // Olaj V4: zawsze po db.php
require_once __DIR__ . '/../../layout/layout_header.php';

// ───────────────────────────────────────────────────────────────
// Konfiguracja/diagnostyka
// ───────────────────────────────────────────────────────────────
$DEBUG = isset($_GET['debug']) && $_GET['debug'] !== '0';
if ($DEBUG) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
}

function e(?string $s): string
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function buildQuery(array $extra = []): string
{
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) unset($base[$k]);
    else $base[$k] = $v;
  }
  return http_build_query($base);
}


function columnExists(PDO $pdo, string $t, string $cname): bool
{
  static $c = [];
  $k = "$t|$cname";
  if (isset($c[$k])) return $c[$k];
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1");
  $st->execute([':t' => $t, ':c' => $cname]);
  return $c[$k] = (bool)$st->fetchColumn();
}
function runQuery(PDO $pdo, string $sql, array $params = [], ?array &$err = null): ?PDOStatement
{
  try {
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
      if (is_int($k)) $st->bindValue($k + 1, $v);
      else $st->bindValue($k, $v);
    }
    $st->execute();
    return $st;
  } catch (Throwable $e) {
    $err = ['message' => $e->getMessage(), 'sql' => $sql, 'params' => $params];
    return null;
  }
}

// ───────────────────────────────────────────────────────────────
// Wejście / filtry
// ───────────────────────────────────────────────────────────────
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$limit   = (int)($_GET['limit'] ?? 100);
if (!in_array($limit, [50, 100], true)) $limit = 100;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $limit;

$category_id  = trim((string)($_GET['category_id'] ?? ''));
$active       = trim((string)($_GET['active'] ?? ''));
$availability = trim((string)($_GET['availability'] ?? ''));
$tag_id       = trim((string)($_GET['tag_id'] ?? ''));
$q            = trim((string)($_GET['q'] ?? '')); // prosta fraza (name/code/sku)

// ───────────────────────────────────────────────────────────────
// Załaduj słowniki (kategorie, tagi) — przez fallback (neutralne dla engine)
// ───────────────────────────────────────────────────────────────
$errors = [];
$categories = [];
$tagsDict   = [];

if (tableExists($pdo, 'categories')) {
  if ($st = runQuery($pdo, "SELECT id,name FROM categories WHERE owner_id=? ORDER BY name ASC", [$ownerId], $err))
    $categories = $st->fetchAll(PDO::FETCH_ASSOC);
  else $errors[] = ['where' => 'load categories', 'err' => $err];
}
if (tableExists($pdo, 'product_tags')) {
  if ($st = runQuery($pdo, "SELECT id,name,color FROM product_tags WHERE owner_id=? ORDER BY name ASC", [$ownerId], $err))
    $tagsDict = $st->fetchAll(PDO::FETCH_ASSOC);
  else $errors[] = ['where' => 'load product_tags', 'err' => $err];
}

// ───────────────────────────────────────────────────────────────
// Pobieranie produktów: preferujemy ENGINE → listProducts().
// Jeśli engine/ metoda nie istnieje, fallback SQL.
// ───────────────────────────────────────────────────────────────
$products    = [];
$total_rows  = 0;
$total_pages = 1;

// ENGINE: require + init
$engineOk = false;
try {
  require_once __DIR__ . '/../../engine/Orders/ProductEngine.php';
  if (class_exists('ProductEngine')) {
    $engine = new ProductEngine($pdo, $ownerId);
    if (method_exists($engine, 'listProducts')) {
      $params = [
        'owner_id'      => $ownerId,
        'category_id'   => ($category_id !== '' ? (int)$category_id : null),
        'tag_id'        => ($tag_id !== '' ? (int)$tag_id : null),
        'active'        => ($active !== '' ? (int)$active : null),
        'availability'  => ($availability !== '' ? $availability : null), // 'in_stock' | 'out_of_stock'
        'q'             => ($q !== '' ? $q : null),
        'limit'         => $limit,
        'page'          => $page,
        'with_tags'     => true,
        'with_images'   => true,
        'with_reserved' => true,
      ];
      $result = $engine->listProducts($params);
      if (is_array($result)) {
        if (isset($result['items'])) {
          $products    = $result['items'];
          $total_rows  = (int)($result['total'] ?? count($products));
          $limit       = (int)($result['limit'] ?? $limit);
          $page        = (int)($result['page'] ?? $page);
        } else {
          $products   = $result;
          if (method_exists($engine, 'countProducts')) {
            $total_rows = (int)$engine->countProducts($params);
          } else {
            if ($st = runQuery($pdo, "SELECT COUNT(*) FROM products WHERE owner_id=?", [$ownerId], $err))
              $total_rows = (int)$st->fetchColumn();
          }
        }
        $engineOk = true;
      }
    }
  }
} catch (Throwable $e) {
  $errors[] = ['where' => 'engine listProducts', 'err' => ['message' => $e->getMessage()]];
  // fallback
}

// FALLBACK SQL jeśli engine nie zadziałał
if (!$engineOk) {
  logg('warning', 'products.index', 'Engine listProducts unavailable, using SQL fallback', ['owner_id' => $ownerId]);

  // autodetekcja kolumn cen/stan
  $hasUnitPriceCol   = columnExists($pdo, 'products', 'unit_price');
  $hasPriceCol       = columnExists($pdo, 'products', 'price');
  $hasStockAvailCol  = columnExists($pdo, 'products', 'stock_available');
  $hasStockCachedCol = columnExists($pdo, 'products', 'stock_cached');
  $hasStockCol       = columnExists($pdo, 'products', 'stock');
  $hasVatRateCol     = columnExists($pdo, 'products', 'vat_rate');
  $hasActiveCol      = columnExists($pdo, 'products', 'active');
  $hasCodeCol        = columnExists($pdo, 'products', 'code');
  $hasTwelveNcCol    = columnExists($pdo, 'products', 'twelve_nc');
  $hasReservations   = tableExists($pdo, 'stock_reservations');
  $hasResOwnerCol    = $hasReservations && columnExists($pdo, 'stock_reservations', 'owner_id');

  $priceExpr  = $hasUnitPriceCol ? "p.unit_price" : ($hasPriceCol ? "p.price" : "NULL");
  $stockExpr  = $hasStockAvailCol ? "p.stock_available" : ($hasStockCachedCol ? "p.stock_cached" : ($hasStockCol ? "p.stock" : "NULL"));
  $vatExpr    = $hasVatRateCol ? "p.vat_rate" : "NULL";
  $codeExpr   = $hasCodeCol ? "p.code" : "NULL";
  $twelveExpr = $hasTwelveNcCol ? "p.twelve_nc" : "NULL";
  $activeExpr = $hasActiveCol ? "p.active" : "1";

  $filters = ["p.owner_id = :owner_id"];
  $params  = [':owner_id' => $ownerId];

  if ($category_id !== '' && tableExists($pdo, 'categories') && columnExists($pdo, 'products', 'category_id')) {
    $filters[] = "p.category_id=:category_id";
    $params[':category_id'] = (int)$category_id;
  }
  if ($active !== '' && $hasActiveCol) {
    $filters[] = "p.active=:active";
    $params[':active'] = (int)$active;
  }
  if ($tag_id !== '' && tableExists($pdo, 'product_tag_links')) {
    $filters[] = "EXISTS(SELECT 1 FROM product_tag_links l WHERE l.product_id=p.id AND l.tag_id=:tag_id)";
    $params[':tag_id'] = (int)$tag_id;
  }
  if ($q !== '') {
    $filters[] = "(p.name LIKE :q OR $codeExpr LIKE :q)";
    $params[':q'] = '%' . $q . '%';
  }
  $where = implode(' AND ', $filters);

  $joinReservations = '';
  if ($hasReservations) {
    if ($hasResOwnerCol) {
      $joinReservations = "
        LEFT JOIN (
          SELECT owner_id, product_id, SUM(qty) AS reserved_qty
          FROM stock_reservations
          WHERE status='reserved'
          GROUP BY owner_id, product_id
        ) sr ON sr.product_id=p.id AND sr.owner_id=p.owner_id
      ";
    } else {
      $joinReservations = "
        LEFT JOIN (
          SELECT product_id, SUM(qty) AS reserved_qty
          FROM stock_reservations
          WHERE status='reserved'
          GROUP BY product_id
        ) sr ON sr.product_id=p.id
      ";
    }
  }

  $stockExprForFilter = $stockExpr;
  $availSQL = '';
  if ($stockExprForFilter !== 'NULL') {
    if ($availability === 'in_stock')
      $availSQL = $hasReservations ? " AND ($stockExprForFilter-COALESCE(sr.reserved_qty,0))>0"  : " AND $stockExprForFilter>0";
    elseif ($availability === 'out_of_stock')
      $availSQL = $hasReservations ? " AND ($stockExprForFilter-COALESCE(sr.reserved_qty,0))<=0" : " AND $stockExprForFilter<=0";
  }

  $sqlBase = "FROM products p $joinReservations WHERE $where";
  if ($st = runQuery($pdo, "SELECT COUNT(*) $sqlBase $availSQL", $params, $err)) {
    $total_rows  = (int)$st->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $limit));
    if ($page > $total_pages) {
      $page = $total_pages;
      $offset = ($page - 1) * $limit;
    }
  } else {
    $errors[] = ['where' => 'count products (fallback)', 'err' => $err];
  }

  $selectReserved = $hasReservations ? "COALESCE(sr.reserved_qty,0) AS reserved_quantity," : "0 AS reserved_quantity,";
  $sqlList = "
      SELECT
        p.id, p.name,
        $codeExpr   AS code,
        $priceExpr  AS price,
        $stockExpr  AS stock_available,
        $vatExpr    AS vat_rate,
        $twelveExpr AS twelve_nc,
        $activeExpr AS active,
        $selectReserved
        NULL AS is_main
      $sqlBase
      $availSQL
      ORDER BY p.name ASC
      LIMIT :limit OFFSET :offset
    ";
  $paramsList = $params + [':limit' => $limit, ':offset' => $offset];

  if ($st = runQuery($pdo, $sqlList, $paramsList, $err)) {
    $products = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $errors[] = ['where' => 'list products (fallback)', 'err' => $err];
    $products = [];
  }
}

// Finalne wyliczenia paginacji
if (!$total_rows) $total_rows = is_array($products) ? count($products) : 0;
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) $page = $total_pages;

// Map tagów do produktów (gdy engine nie dodał)
$tagsByProduct = [];
if (!empty($products) && empty($products[0]['tags']) && tableExists($pdo, 'product_tag_links') && tableExists($pdo, 'product_tags')) {
  $ids = array_column($products, 'id');
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $bind = [$ownerId];
    foreach ($ids as $id) $bind[] = $id;
    $sqlTags = "
          SELECT l.product_id, t.name, t.color
          FROM product_tag_links l
          JOIN product_tags t ON t.id=l.tag_id AND t.owner_id=?
          WHERE l.product_id IN ($in)";
    if ($st = runQuery($pdo, $sqlTags, $bind, $err)) {
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $tagsByProduct[(int)$r['product_id']][] = ['name' => $r['name'], 'color' => $r['color']];
      }
    } else {
      $errors[] = ['where' => 'load tag links', 'err' => $err];
    }
  }
}
// Miniatury: jeśli engine nie dał 'is_main', spróbujemy z product_images (jeśli jest)
$imagesByProduct = [];
if (!empty($products) && (empty($products[0]['is_main']) || $products[0]['is_main'] === null)) {
  if (
    function_exists('tableExists') && tableExists($pdo, 'product_images')
    && columnExists($pdo, 'product_images', 'product_id')
    && columnExists($pdo, 'product_images', 'image_path')
  ) {

    $ids = array_column($products, 'id');
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));

      // Ułóż porządek preferując "is_main" (jeśli istnieje), potem najstarsze/pozycjonowane
      $order = [];
      if (columnExists($pdo, 'product_images', 'is_main')) $order[] = "is_main DESC";
      if (columnExists($pdo, 'product_images', 'position')) $order[] = "position ASC";
      if (columnExists($pdo, 'product_images', 'sort'))     $order[] = "sort ASC";
      $order[] = "id ASC";
      $orderBy = "ORDER BY " . implode(',', $order);

      $sql = "SELECT product_id, image_path FROM product_images WHERE product_id IN ($in) $orderBy";
      if ($st = runQuery($pdo, $sql, $ids, $err)) {
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $pid = (int)$r['product_id'];
          if (!isset($imagesByProduct[$pid])) { // pierwsze dla danego produktu
            $imagesByProduct[$pid] = (string)$r['image_path'];
          }
        }
      }
    }
  }
}

// ───────────────────────────────────────────────────────────────
// RENDER
// ───────────────────────────────────────────────────────────────
$__current = basename($_SERVER['PHP_SELF'] ?? '');
$__tab_is_list    = ($__current === 'index.php');
$__tab_is_kupione = ($__current === 'kupione.php');
$__tab_is_rezerwacje = ($__current === 'rezerwacje.php');
$__tab_is_ruchy = ($__current === 'stock_movements.php');
?>
<main id="content" class="mx-auto px-5 pt-4" style="max-width: 1800px;">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div class="flex items-center gap-3">
      <h1 class="text-2xl font-bold tracking-tight">📦 Produkty</h1>
      <span class="chip">Rekordów: <strong><?= (int)$total_rows ?></strong></span>
      <span id="visible-counter" class="chip hidden">Widoczne: <strong id="visible-count">0</strong></span>
      <?php if ($DEBUG): ?><span class="chip" title="Debug on">DEBUG</span><?php endif; ?>
    </div>

    <div class="flex items-center gap-2">
      <div class="flex items-center gap-2 bg-white border rounded-xl px-3 py-2">
        <input id="search-input" class="input border-0 focus:ring-0 focus:outline-none" placeholder="Szukaj nazwy / kodu…" style="width:240px;height:32px" oninput="debouncedFilter()">
        <button type="button" onclick="resetSearch()" class="text-sm text-slate-500 hover:underline">Wyczyść</button>
      </div>
      <a href="/admin/products/kupione.php" class="btn btn-light shadow-sm">📥 Kupione</a>
      <a href="/admin/products/create.php" class="btn btn-primary shadow-sm">➕ Dodaj produkt</a>
    </div>
  </div>

  <!-- Zakładki: Lista / Kupione -->
  <nav class="subtabs mb-3" aria-label="Sekcje produktów">
    <a href="/admin/products/index.php" class="subtab <?= $__tab_is_list ? 'is-active' : '' ?>" aria-current="<?= $__tab_is_list ? 'page' : 'false' ?>">Lista</a>
    <a href="/admin/products/kupione.php" class="subtab <?= $__tab_is_kupione ? 'is-active' : '' ?>">Kupione</a>
    <a href="/admin/products/rezerwacje.php" class="subtab <?= $__tab_is_rezerwacje ? 'is-active' : '' ?>">Rezerwacje</a>
    <a href="/admin/products/stock_movements.php" class="subtab <?= $__tab_is_ruchy ? 'is-active' : '' ?>">Ruchy magazynowe</a>
  </nav>

  <?php if ($DEBUG): ?>
    <div class="mb-4 p-3 rounded-xl border border-yellow-300 bg-yellow-50 text-sm">
      <div class="font-semibold mb-1">DEBUG</div>
      <pre><?php
            echo e(json_encode([
              'owner_id' => $ownerId,
              'limit' => $limit,
              'page' => $page,
              'total_rows' => $total_rows,
              'total_pages' => $total_pages,
              'filters' => ['category_id' => $category_id, 'active' => $active, 'availability' => $availability, 'tag_id' => $tag_id, 'q' => $q],
              'engine_ok' => $engineOk,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            ?></pre>
      <?php if (!empty($errors)): ?>
        <ul class="mt-2 list-disc ml-6">
          <?php foreach ($errors as $er): ?>
            <li><b><?= e($er['where']) ?>:</b> <?= e($er['err']['message'] ?? 'ok') ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php elseif (!empty($errors)): ?>
    <div class="mb-4 p-3 rounded-xl border border-yellow-300 bg-yellow-50 text-sm">
      Część danych mogła nie zostać załadowana poprawnie.
      <a href="?<?= e(buildQuery(['debug' => 1])) ?>" class="underline">Pokaż diagnostykę</a>.
    </div>
  <?php endif; ?>

  <!-- Filtry paskowe -->
  <form method="get" class="flex flex-wrap items-center gap-2 mb-4 bg-white border rounded-2xl p-3 shadow-sm" id="filter-form">
    <div class="chip">
      Limit
      <select name="limit" class="input" style="height:34px;" onchange="this.form.submit()">
        <option value="50" <?= $limit === 50  ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
      </select>
    </div>

    <?php if (!empty($categories)): ?>
      <div class="chip">
        Kategoria
        <select name="category_id" class="input" style="height:34px;" onchange="this.form.submit()">
          <option value="">Wszystkie</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= ($category_id !== '' && (int)$category_id === (int)$cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="chip">
      Status
      <select name="active" class="input" style="height:34px;" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <option value="1" <?= $active === '1' ? 'selected' : '' ?>>Aktywne</option>
        <option value="0" <?= $active === '0' ? 'selected' : '' ?>>Nieaktywne</option>
      </select>
    </div>

    <div class="chip">
      Dostępność
      <select name="availability" class="input" style="height:34px;" onchange="this.form.submit()">
        <option value="">Wszystkie</option>
        <option value="in_stock" <?= $availability === 'in_stock' ? 'selected' : '' ?>>Na stanie</option>
        <option value="out_of_stock" <?= $availability === 'out_of_stock' ? 'selected' : '' ?>>Brak</option>
      </select>
    </div>

    <?php if (!empty($tagsDict)): ?>
      <div class="chip">
        Tag
        <select name="tag_id" class="input" style="height:34px;" onchange="this.form.submit()">
          <option value="">Wszystkie</option>
          <?php foreach ($tagsDict as $tag): ?>
            <option value="<?= (int)$tag['id'] ?>" <?= ($tag_id !== '' && (int)$tag_id === (int)$tag['id']) ? 'selected' : '' ?>><?= e($tag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="chip">
      Fraza
      <input type="text" name="q" value="<?= e($q) ?>" class="input" placeholder="np. nazwa, kod" style="height:34px; width:220px;">
    </div>

    <button type="submit" class="btn btn-light">Filtruj</button>

    <!-- MASOWE AKCJE -->
    <div class="mt-3 mb-2 p-3 border rounded bg-white/70 w-full">
      <div class="flex flex-col md:flex-row md:items-center gap-3">
        <div class="text-sm text-gray-600">
          Zaznaczone: <span id="bulk-selected-count" class="font-semibold">0</span>
        </div>

        <select id="bulk-action" class="border rounded px-2 py-1">
          <option value="">— wybierz akcję —</option>
          <option value="activate">Aktywuj</option>
          <option value="deactivate">Dezaktywuj</option>
          <option value="delete_soft">Usuń (miękko)</option>
          <option value="price_set">Ustaw cenę</option>
          <option value="price_change_pct">Zmiana ceny %</option>
          <option value="vat_set">Ustaw VAT</option>
          <option value="stock_adjust">Korekta stanu (+/−)</option>
          <option value="category_set">Ustaw kategorię</option>
        </select>

        <div id="bulk-fields" class="flex flex-wrap gap-2 items-center text-sm"></div>

        <button id="bulk-apply" class="px-3 py-1 bg-blue-600 text-white rounded disabled:opacity-50" disabled>Wykonaj</button>
      </div>
      <div id="bulk-msg" class="mt-2 text-sm" role="status" aria-live="polite"></div>
    </div>

    <div class="ml-auto text-xs text-slate-500 flex items-center gap-2">
      Skróty: <span class="kbd">/</span> fokus szukaj • <span class="kbd">Esc</span> wyczyść
    </div>
  </form>

  <!-- Tabela -->
  <div class="table-wrap bg-white border rounded-2xl shadow-sm">
    <table id="products-table" class="w-full text-sm">
      <thead class="sticky">
        <tr class="text-left text-slate-600">
          <th class="py-3 px-3 w-10"><input type="checkbox" id="select-all"></th>
          <th class="py-3 px-3">Produkt</th>
          <th class="py-3 px-3">Cena</th>
          <th class="py-3 px-3">Stan</th>
          <th class="py-3 px-3">VAT</th>
          <th class="py-3 px-3">Kod</th>
          <th class="py-3 px-3">12nc</th>
          <th class="py-3 px-3 text-right">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p):
          $reserved  = (int)($p['reserved_quantity'] ?? 0);
          $stockVal  = isset($p['stock_available']) ? (float)$p['stock_available'] : null;
          $available = is_null($stockVal) ? null : max(0.0, $stockVal - $reserved);

          $priceVal = $p['price'] ?? $p['unit_price'] ?? null;
          $priceStr = $priceVal !== null ? number_format((float)$priceVal, 2, ',', ' ') . ' zł' : '–';
          $stockStr = is_null($stockVal) ? '–' : (string)$stockVal;
          $vatStr   = isset($p['vat_rate']) && $p['vat_rate'] !== null ? number_format((float)$p['vat_rate'], 2, ',', ' ') . '%' : '–';
          $availStr = is_null($available) ? '' : "<span class=\"text-xs text-gray-500\">(dost.: {$available})</span>";
          $tags     = $p['tags'] ?? ($tagsByProduct[(int)($p['id'] ?? 0)] ?? []);
        ?>
          <tr class="row border-b last:border-0">
            <td class="py-2 px-3 align-top"><input type="checkbox" class="row-checkbox" value="<?= (int)$p['id'] ?>"></td>
            <td class="py-3 px-3 align-top searchable">
              <?php
              $pid = (int)($p['id'] ?? 0);

              // Użyj product_images z is_main = 1
              $imgPath = $imagesByProduct[$pid] ?? '';

              // Doklej /uploads/
              if ($imgPath !== '' && !str_starts_with($imgPath, '/')) {
                $imgPath = '/uploads/' . ltrim($imgPath, '/');
              }

              // Fallback
              $src = $imgPath !== '' ? $imgPath : '/static/no-image.png';
              ?>
              <img src="<?= htmlspecialchars($src) ?>"
                class="prod-thumb"
                style="max-height:64px; border:1px solid #ccc"
                loading="lazy"
                onerror="this.onerror=null;this.src='/static/no-image.png';">


              <div class="min-w-0">
                <div class="font-semibold text-slate-900 truncate"><?= e((string)($p['name'] ?? '')) ?></div>
                <?php if (!empty($tags)): ?>
                  <div class="mt-1 flex flex-wrap gap-1">
                    <?php foreach ($tags as $t):
                      $color = is_array($t) ? ($t['color'] ?? '#888') : '#888';
                      $name  = is_array($t) ? ($t['name']  ?? '')    : (string)$t; ?>
                      <span class="badge" style="background: <?= e($color) ?>15; color:#111;">
                        <span class="w-2 h-2 rounded-full" style="background: <?= e($color) ?>"></span>
                        <?= e($name) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
  </div>
  </td>

  <td class="py-3 px-3 align-top"><?= $priceStr ?></td>
  <td class="py-3 px-3 align-top"><?= $stockStr ?> <?= $availStr ?></td>
  <td class="py-3 px-3 align-top"><?= $vatStr ?></td>
  <td class="py-3 px-3 align-top text-xs text-slate-600"><?= e((string)($p['code'] ?? '')) ?></td>
  <td class="py-3 px-3 align-top text-xs text-slate-600"><?= e((string)($p['twelve_nc'] ?? '')) ?></td>
  <td class="py-3 px-3 align-top text-right">
    <a class="btn btn-light" href="/admin/products/edit.php?id=<?= (int)$p['id'] ?>">Edytuj</a>
  </td>
  </tr>
<?php endforeach; ?>
<?php if (!$products): ?>
  <tr>
    <td colspan="8" class="py-10 text-center text-slate-500">Brak produktów dla wybranych filtrów.</td>
  </tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- Paginacja -->
<div class="mt-5 flex flex-wrap gap-2 justify-center">
  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="?<?= e(buildQuery(['page' => $i])) ?>"
      class="px-3 py-1.5 border rounded-xl <?= $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'text-slate-700 bg-white hover:bg-slate-50' ?>">
      <?= $i ?>
    </a>
  <?php endfor; ?>
</div>
</main>

<style>
  .prod-thumb {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 0 rgba(0, 0, 0, .03);
  }

  .table-wrap {
    overflow: auto;
    max-height: calc(100vh - 260px);
    border-radius: 12px;
    box-shadow: 0 1px 0 #e5e7eb inset;
  }

  thead.sticky th {
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
    box-shadow: 0 1px 0 #e5e7eb;
  }

  .badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .75rem;
    padding: .15rem .5rem;
    border-radius: 9999px;
    border: 1px solid rgba(0, 0, 0, .06);
  }

  .btn {
    border-radius: 10px;
    padding: .55rem .9rem;
    font-weight: 600;
    transition: .15s;
  }

  .btn-primary {
    background: #2563eb;
    color: #fff;
  }

  .btn-light {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
  }

  .input {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: .5rem .7rem;
    outline: 0;
  }

  .input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
  }

  .chip {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #0f172a;
    padding: .35rem .6rem;
    border-radius: 9999px;
    font-size: .8rem;
  }

  .row {
    transition: background .08s;
  }

  .row:hover {
    background: #f8fafc;
  }

  .kbd {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    font-size: .75rem;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: .1rem .35rem;
    border-radius: 6px;
  }

  tbody tr:nth-child(even) {
    background: #fcfcfd;
  }

  .dot {
    width: .5rem;
    height: .5rem;
    border-radius: 50%
  }

  /* Subtabs */
  .subtabs {
    display: flex;
    gap: .5rem;
    border-bottom: 1px solid #e5e7eb;
  }

  .subtab {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .45rem .8rem;
    border: 1px solid transparent;
    border-bottom: 0;
    border-radius: 10px 10px 0 0;
    text-decoration: none;
    color: #0f172a;
    background: #f8fafc;
  }

  .subtab:hover {
    background: #f1f5f9;
  }

  .subtab.is-active {
    background: #fff;
    border-color: #e5e7eb;
    border-bottom-color: #fff;
    box-shadow: 0 -1px 0 #e5e7eb inset;
    font-weight: 600;
  }
</style>

<script>
  // Szukajka z debounce + licznik widocznych
  var visibleCounterWrap = document.getElementById('visible-counter');
  var visibleCount = document.getElementById('visible-count');

  function filterProducts() {
    var input = document.getElementById('search-input');
    var q = (input && input.value ? input.value : '').toLowerCase();
    var rows = document.querySelectorAll('#products-table tbody tr');
    var vis = 0;
    rows.forEach(function(row) {
      var cell = row.querySelector('.searchable');
      if (!cell) return;
      var s = (cell.innerText || '').toLowerCase();
      var show = s.indexOf(q) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) vis++;
    });
    if (visibleCounterWrap) {
      if (q && vis !== null) {
        visibleCounterWrap.classList.remove('hidden');
        if (visibleCount) visibleCount.textContent = String(vis);
      } else {
        visibleCounterWrap.classList.add('hidden');
      }
    }
  }
  var _debTimer = null;

  function debouncedFilter() {
    clearTimeout(_debTimer);
    _debTimer = setTimeout(filterProducts, 120);
  }

  function resetSearch() {
    var i = document.getElementById('search-input');
    if (i) {
      i.value = '';
      filterProducts();
    }
  }

  // Zaznacz wszystko
  var selectAllEl = document.getElementById('select-all');
  if (selectAllEl) {
    selectAllEl.addEventListener('change', function() {
      document.querySelectorAll('.row-checkbox').forEach(function(cb) {
        cb.checked = !!selectAllEl.checked;
      });
    });
  }

  // Hotkeys
  window.addEventListener('keydown', function(e) {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      var si = document.getElementById('search-input');
      if (si) si.focus();
    }
    if (e.key === 'Escape') {
      resetSearch();
    }
  });
</script>

<script>
  // ====== MASOWE AKCJE (czysty JS) ======
  (function() {
    var table = document.getElementById('products-table');
    var selectAll = document.getElementById('select-all');
    var bulkCount = document.getElementById('bulk-selected-count');
    var bulkSel = document.getElementById('bulk-action');
    var bulkFields = document.getElementById('bulk-fields');
    var bulkApply = document.getElementById('bulk-apply');
    var bulkMsg = document.getElementById('bulk-msg');

    function getSelectedIds() {
      var ids = [];
      document.querySelectorAll('.row-checkbox:checked').forEach(function(cb) {
        var v = parseInt(cb.value, 10);
        if (v) ids.push(v);
      });
      return ids;
    }

    function refreshCount() {
      var n = getSelectedIds().length;
      if (bulkCount) bulkCount.textContent = String(n);
      if (bulkApply) bulkApply.disabled = !bulkSel.value || n === 0;
    }

    if (selectAll) {
      selectAll.addEventListener('change', function() {
        document.querySelectorAll('.row-checkbox').forEach(function(cb) {
          cb.checked = !!selectAll.checked;
        });
        refreshCount();
      });
    }

    if (table) {
      table.addEventListener('change', function(e) {
        var t = e.target;
        if (t && t.classList.contains('row-checkbox')) {
          refreshCount();
        }
      });
    }

    function renderFields(action) {
      if (!bulkFields) return;
      bulkFields.innerHTML = '';

      function el(html) {
        var d = document.createElement('div');
        d.innerHTML = html.trim();
        return d.firstElementChild;
      }
      switch (action) {
        case 'price_set':
          bulkFields.appendChild(el('<label class="flex items-center gap-1"><span>Cena (zł):</span><input type="number" step="0.01" id="bulk-price" class="border rounded px-2 py-1" value="0"></label>'));
          break;
        case 'price_change_pct':
          bulkFields.appendChild(el('<label class="flex items-center gap-1"><span>Zmiana %:</span><input type="number" step="0.01" id="bulk-pct" class="border rounded px-2 py-1" value="10"></label>'));
          break;
        case 'vat_set':
          bulkFields.appendChild(el('<label class="flex items-center gap-1"><span>VAT %:</span><input type="number" step="0.01" id="bulk-vat" class="border rounded px-2 py-1" value="23"></label>'));
          break;
        case 'stock_adjust':
          bulkFields.appendChild(el('<label class="flex items-center gap-1"><span>Delta stanu:</span><input type="number" step="0.01" id="bulk-delta" class="border rounded px-2 py-1" value="1"></label>'));
          break;
        case 'category_set':
          bulkFields.appendChild(el('<label class="flex items-center gap-1"><span>Kategoria:</span><select id="bulk-category" class="border rounded px-2 py-1"><option value="">— brak —</option><?php if (!empty($categories)): foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option><?php endforeach;
                                                                                                                                                                                                                                                                                                                                    endif; ?></select></label>'));
          break;
        default:
          break;
      }
    }

    if (bulkSel) {
      bulkSel.addEventListener('change', function() {
        renderFields(bulkSel.value);
        refreshCount();
        if (bulkMsg) bulkMsg.textContent = '';
      });
    }

    function applyBulk() {
      var ids = getSelectedIds();
      if (!bulkSel || !bulkSel.value || ids.length === 0) return;

      var data = {};
      if (bulkSel.value === 'price_set') data.price = parseFloat((document.getElementById('bulk-price') && document.getElementById('bulk-price').value) || '0');
      if (bulkSel.value === 'price_change_pct') data.pct = parseFloat((document.getElementById('bulk-pct') && document.getElementById('bulk-pct').value) || '0');
      if (bulkSel.value === 'vat_set') data.vat = parseFloat((document.getElementById('bulk-vat') && document.getElementById('bulk-vat').value) || '23');
      if (bulkSel.value === 'stock_adjust') data.delta = parseFloat((document.getElementById('bulk-delta') && document.getElementById('bulk-delta').value) || '0');
      if (bulkSel.value === 'category_set') data.category_id = (document.getElementById('bulk-category') && document.getElementById('bulk-category').value) || null;

      if (bulkApply) bulkApply.disabled = true;
      if (bulkMsg) {
        bulkMsg.className = 'mt-2 text-sm text-gray-600';
        bulkMsg.textContent = 'Przetwarzanie…';
      }

      fetch('/admin/products/api/bulk_action.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          credentials: 'include',
          body: JSON.stringify({
            action: bulkSel.value,
            ids: ids,
            data: data
          })
        })
        .then(function(res) {
          return res.json();
        })
        .then(function(json) {
          if (!json.ok) throw new Error(json.error || 'bulk_failed');
          if (bulkMsg) {
            bulkMsg.className = 'mt-2 text-sm text-green-600';
            bulkMsg.textContent = '✔ Zrobione';
          }
          setTimeout(function() {
            location.reload();
          }, 400);
        })
        .catch(function(e) {
          if (bulkMsg) {
            bulkMsg.className = 'mt-2 text-sm text-red-600';
            bulkMsg.textContent = '❌ Błąd: ' + (e && e.message ? e.message : 'nieznany');
          }
          if (bulkApply) bulkApply.disabled = false;
        });
    }

    var bulkApplyBtn = document.getElementById('bulk-apply');
    if (bulkApplyBtn) bulkApplyBtn.addEventListener('click', applyBulk);
  })();
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>