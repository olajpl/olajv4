<?php
// admin/products/edit.php — Panel edycji produktu z insightami + upload obrazka (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../engine/Product/ProductEngine.php';

use Engine\Product\ProductEngine;
use Engine\Log\LogEngine;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// ── lokalne helpery ───────────────────────────────────────────
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
// podmień istniejącą funkcję na tę:
function normalizeImageUrl(string $img, int $productId, int $ownerId): string
{
    $img = trim($img);
    if ($img === '') return '';

    // pełny URL lub absolutna ścieżka
    if (preg_match('~^https?://~i', $img)) return $img;
    if ($img[0] === '/') return $img;

    // już z prefiksem uploads/
    if (preg_match('~^/?uploads/~i', $img)) return '/' . ltrim($img, '/');

    // nowy format: {ownerId}/plik.webp
    if (preg_match('~^\d+/[^/].+~', $img)) {
        return '/uploads/' . ltrim($img, '/');
    }

    // starszy format: products/{id}/plik.webp
    if (preg_match('~^products/\d+/~i', $img)) {
        return '/uploads/' . ltrim($img, '/');
    }

    // fallback: potraktuj jako nazwę pliku w katalogu ownera
    return '/uploads/' . $ownerId . '/' . ltrim($img, '/');
}


$owner_id   = (int)($_SESSION['user']['owner_id'] ?? 0);
$product_id = (int)($_GET['id'] ?? 0);
$DEBUG      = isset($_GET['debug']) && $_GET['debug'] !== '0';

if ($owner_id <= 0 || $product_id <= 0) {
    http_response_code(400);
    exit('❌ Brak owner_id lub brak parametru ?id=…');
}

try {
    $engine  = new ProductEngine($pdo, $owner_id);

    // Produkt + tagi
    $product = $engine->getWithTags($product_id);
    if (!$product) {
        http_response_code(404);
        exit('❌ Nie znaleziono produktu.');
    }

    // Stany
    $stockInfo = $engine->getStockStatus($product_id) ?? [
        'stock_cached'   => 0,
        'stock_reserved' => 0,
        'stock_free'     => 0,
    ];
    $product['stock']          = (float)($stockInfo['stock_cached'] ?? 0);
    $product['stock_reserved'] = (float)($stockInfo['stock_reserved'] ?? 0);
    $product['free_stock']     = (float)($stockInfo['stock_free'] ?? max(0, ($product['stock'] ?? 0) - ($product['stock_reserved'] ?? 0)));

    // Kategorie (opcjonalnie)
    $categories = [];
    if ($pdo->query("SHOW TABLES LIKE 'categories'")->rowCount() > 0) {
        $st = $pdo->prepare("SELECT id,name FROM categories WHERE owner_id=? ORDER BY name ASC");
        $st->execute([$owner_id]);
        $categories = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Wszystkie tagi (opcjonalnie)
    $all_tags = [];
    if ($pdo->query("SHOW TABLES LIKE 'product_tags'")->rowCount() > 0) {
        $st = $pdo->prepare("SELECT id,name,color FROM product_tags WHERE owner_id=? ORDER BY name ASC");
        $st->execute([$owner_id]);
        $all_tags = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Wybrane tagi z getWithTags()
    $selected_tags = array_map('intval', array_column($product['tags'] ?? [], 'id'));

    // Obrazek główny (owner-safe + is_main jeśli kolumna istnieje) → $currentUrl
    $product_image = null;
    $currentUrl = '';
    if ($pdo->query("SHOW TABLES LIKE 'product_images'")->rowCount() > 0) {
        $hasOwner = (bool)$pdo->query("
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'product_images'
               AND COLUMN_NAME  = 'owner_id'
             LIMIT 1
        ")->fetchColumn();
        $hasIsMain = (bool)$pdo->query("
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'product_images'
               AND COLUMN_NAME  = 'is_main'
             LIMIT 1
        ")->fetchColumn();

        $sql = "
            SELECT image_path
              FROM product_images
             WHERE product_id = ?
               " . ($hasOwner ? "AND owner_id = ?" : "") . "
               " . ($hasIsMain ? "AND is_main = 1" : "") . "
             ORDER BY uploaded_at DESC, id DESC
             LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $bind = [$product_id];
        if ($hasOwner) $bind[] = $owner_id;
        $st->execute($bind);
        $product_image = $st->fetchColumn() ?: null;
        if ($product_image) $currentUrl = normalizeImageUrl((string)$product_image, $product_id, $owner_id);
    }

    // Ostatnie rezerwacje (8)
    $lastResvRows = [];
    if ($pdo->query("SHOW TABLES LIKE 'stock_reservations'")->rowCount() > 0) {
        $st = $pdo->prepare("
            SELECT id, qty, status, source_type, live_id, reserved_at
            FROM stock_reservations
            WHERE product_id=? AND owner_id=?
            ORDER BY reserved_at DESC, id DESC
            LIMIT 8
        ");
        $st->execute([$product_id, $owner_id]);
        $lastResvRows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Ruchy magazynowe (10)
    $stockMoves = [];
    if ($pdo->query("SHOW TABLES LIKE 'stock_movements'")->rowCount() > 0) {
        $st = $pdo->prepare("
            SELECT id, movement_type, qty, note, created_at
            FROM stock_movements
            WHERE product_id=? AND owner_id=?
            ORDER BY created_at DESC, id DESC
            LIMIT 10
        ");
        $st->execute([$product_id, $owner_id]);
        $stockMoves = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Sprzedaż (30 dni) + ostatnie 8
    $from30 = (new DateTime('-30 days', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d H:i:s');
    $sales30       = $engine->salesSummary($product_id, $from30); // ['rows_cnt','qty_sum','revenue']
    $lastSalesRows = $engine->lastSales($product_id, 8);

    // Waga -> UI (kg)
    $weightHint = '';
    $weightValueKg = '';
    if (array_key_exists('weight_grams', $product)) {
        $weightValueKg = number_format(((float)$product['weight_grams']) / 1000, 3, '.', '');
        $weightHint = '(w bazie w gramach — przeliczane)';
    } elseif (array_key_exists('weight', $product)) {
        $weightValueKg = (string)$product['weight'];
        $weightHint = '(w bazie w kilogramach)';
    } else {
        $weightHint = '(brak kolumny wagi w bazie)';
    }

    // Komunikaty sesyjne
    $success = $_SESSION['success_message'] ?? null;
    $error   = $_SESSION['error_message'] ?? null;
    unset($_SESSION['success_message'], $_SESSION['error_message']);
} catch (\Throwable $e) {
    logg('error', 'products.edit', 'exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString(),
    ], [
        'owner_id'   => $owner_id,
        'source'     => 'panel',
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ]);
    http_response_code(500);
    exit('Błąd: ' . $e->getMessage());
}

// ——— layout (tylko raz) ———
require_once __DIR__ . '/../../layout/layout_header.php';

// Status ENUM -> kolory badge
$status = (string)($product['status'] ?? (($product['active'] ?? 1) ? 'active' : 'inactive'));
$badgeClass = 'bg-slate-100 text-slate-700';
if ($status === 'active')   $badgeClass = 'bg-green-100 text-green-800';
elseif ($status === 'inactive') $badgeClass = 'bg-gray-200 text-gray-700';
elseif ($status === 'draft')    $badgeClass = 'bg-yellow-100 text-yellow-800';
elseif ($status === 'deleted')  $badgeClass = 'bg-red-100 text-red-800';

// Liczby/formaty
$price = (float)($product['unit_price'] ?? $product['price'] ?? 0);
$vat   = (float)($product['vat_rate'] ?? 23);
$code  = (string)($product['code'] ?? '');
$name  = (string)($product['name'] ?? '');
$catId = (int)($product['category_id'] ?? 0);
?>
<main id="content" class="mx-auto px-5 pt-4" style="max-width: 1400px;">
    <?php if ($DEBUG): ?>
        <div class="mb-4 p-3 rounded-xl border border-yellow-300 bg-yellow-50 text-sm">
            <div class="font-semibold mb-1">DEBUG</div>
            <pre><?php
                    echo htmlspecialchars(json_encode([
                        'product_id' => $product_id,
                        'owner_id'   => $owner_id,
                        'status'     => $status,
                        'stock'      => $product['stock'],
                        'reserved'   => $product['stock_reserved'],
                        'free'       => $product['free_stock'],
                        'has_image'  => (bool)$product_image,
                        'currentUrl' => $currentUrl,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?></pre>
        </div>
    <?php endif; ?>

    <!-- Sticky action bar -->
    <div class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b mb-4">
        <div class="flex items-center justify-between py-3">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold truncate"><?= h($name ?: 'Produkt #' . $product_id) ?></h1>
                <span class="badge <?= $badgeClass ?> border-0 capitalize"><?= h($status) ?></span>
                <?php if ($product['free_stock'] <= 0): ?>
                    <span class="badge bg-rose-100 text-rose-800 border-0">Brak wolnego stanu</span>
                <?php endif; ?>
                <?php if ($product['stock_reserved'] > 0): ?>
                    <span class="badge bg-blue-100 text-blue-800 border-0">Rezerwacje: <?= (int)$product['stock_reserved'] ?></span>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-2">
                <button form="product-form" type="submit" class="btn btn-primary">💾 Zapisz (Ctrl/Cmd+S)</button>
                <a href="/admin/products/index.php" class="btn btn-light">↩ Powrót</a>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-3 p-3 rounded-xl border border-green-300 bg-green-50 text-sm"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-3 p-3 rounded-xl border border-red-300 bg-red-50 text-sm"><?= h($error) ?></div>
    <?php endif; ?>

    <form id="product-form" method="post" action="/admin/products/api/product_update.php" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$product_id ?>">

        <!-- kolumna 1-2: główne -->
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white border rounded-2xl p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Nazwa *</span>
                        <input class="input" type="text" name="name" required value="<?= h($name) ?>">
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Kod *</span>
                        <input class="input" type="text" name="code" required value="<?= h($code) ?>">
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Cena (zł)</span>
                        <input class="input" type="number" step="0.01" name="unit_price" value="<?= number_format($price, 2, '.', '') ?>">
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">VAT %</span>
                        <input class="input" type="number" step="0.01" name="vat_rate" value="<?= number_format($vat, 2, '.', '') ?>">
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Status</span>
                        <select class="input" name="status">
                            <?php
                            $statuses = ['active' => 'Aktywny', 'inactive' => 'Nieaktywny', 'draft' => 'Szkic', 'deleted' => 'Usunięty'];
                            foreach ($statuses as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Kategoria</span>
                        <select class="input" name="category_id">
                            <option value="">— brak —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= $catId === (int)$cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Waga [kg] <span class="text-xs text-slate-500"><?= h($weightHint) ?></span></span>
                        <input class="input" type="number" step="0.001" name="weight_kg_ui" value="<?= h($weightValueKg) ?>">
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-sm text-gray-600">Dostępność od</span>
                        <input class="input" type="datetime-local" name="available_from" value="<?= !empty($product['available_from']) ? h(date('Y-m-d\TH:i', strtotime($product['available_from']))) : '' ?>">
                    </label>
                </div>
            </div>

            <!-- Tagi -->
            <div class="bg-white border rounded-2xl p-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="font-semibold">Tagi</h2>
                    <div class="flex items-center gap-2">
                        <input id="tag-quick-input" class="input" placeholder="Wpisz tag i Enter lub przecinek…" style="height:34px; max-width:260px;">
                        <button type="button" class="btn btn-primary" id="btn-save-tags">💾 Zapisz tagi</button>
                        <span id="tags-status" class="text-xs text-slate-500"></span>
                    </div>
                </div>


                <!-- chips (tu pojawią się aktualne tagi produktu) -->
                <div id="tag-chips" class="flex flex-wrap gap-1 mb-3"></div>

                <!-- stara lista checkboxów (zostawiamy jako referencję/zaawansowane) -->
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-slate-500">Zaawansowane (pełna lista):</span>
                    <input id="tag-filter" class="input" placeholder="Filtruj tagi…" style="height:34px; max-width:220px;">
                </div>
                <div class="flex flex-wrap gap-2" id="tags-wrap">
                    <?php foreach ($all_tags as $t):
                        $checked = in_array((int)$t['id'], $selected_tags, true); ?>
                        <label class="chip" data-name="<?= htmlspecialchars(mb_strtolower($t['name']), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="checkbox" name="tags[]" value="<?= (int)$t['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                            <span class="w-2 h-2 rounded-full" style="background: <?= htmlspecialchars($t['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                            <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$all_tags): ?>
                        <div class="text-sm text-slate-500">Brak tagów.</div>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Opis --><!-- Opis -->
            <div class="bg-white border rounded-2xl p-4">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="font-semibold">Opis</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-light" id="btn-ai-desc">✨ AI opis</button>
                        <span id="ai-desc-status" class="text-xs text-slate-500"></span>
                    </div>
                </div>

                <label class="flex flex-col gap-1">
                    <span class="text-sm text-gray-600">Treść</span>
                    <textarea id="desc-textarea"
                        class="input"
                        name="description"
                        rows="10"
                        style="width:100%;min-height:260px;resize:vertical"><?= h((string)($product['description'] ?? '')) ?></textarea>
                </label>
            </div>


        </div>

        <!-- kolumna 3: boczne karty -->
        <aside class="space-y-4">
            <div class="bg-white border rounded-2xl p-4">
                <h3 class="font-semibold mb-2">Stan magazynowy</h3>
                <div class="text-sm text-slate-700">Na stanie: <b><?= (float)$product['stock'] ?></b></div>
                <div class="text-sm text-slate-700">Rezerwacje: <b><?= (float)$product['stock_reserved'] ?></b></div>
                <div class="text-sm text-slate-700">Wolne: <b><?= (float)$product['free_stock'] ?></b></div>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="btn btn-light" id="btn-stock-minus">−1</button>
                    <button type="button" class="btn btn-light" id="btn-stock-plus">+1</button>
                    <button type="button" class="btn btn-primary" id="btn-stock-modal">Korekta…</button>
                </div>
            </div>

            <div class="bg-white border rounded-2xl p-4">
                <h3 class="font-semibold mb-2">Sprzedaż (30 dni)</h3>
                <div class="text-sm text-slate-700">Transakcji: <b><?= (int)($sales30['rows_cnt'] ?? 0) ?></b></div>
                <div class="text-sm text-slate-700">Sztuk: <b><?= (int)($sales30['qty_sum'] ?? 0) ?></b></div>
                <div class="text-sm text-slate-700">Przychód: <b><?= number_format((float)($sales30['revenue'] ?? 0), 2, ',', ' ') ?> zł</b></div>
                <canvas id="sales-spark" width="320" height="60" style="width:100%;height:60px" class="mt-3"></canvas>
            </div>


            <!-- ====== OBRAZEK GŁÓWNY + UPLOAD/PASTE/DRAG ====== -->
            <div class="bg-white border rounded-2xl p-4">
                <h3 class="font-semibold mb-3">Obrazek główny</h3>

                <div id="image-dropzone"
                    class="border-2 border-dashed rounded-xl p-3 text-sm text-slate-600 flex flex-col items-center gap-2 dropzone"
                    aria-label="Strefa wgrywania obrazka" tabindex="0">
                    <div class="w-full mb-2">
                        <?php if ($currentUrl): ?>
                            <img id="img-preview" src="<?= h($currentUrl) ?>" alt="<?= h($product['name'] ?? '') ?>"
                                class="w-full rounded-lg border" style="max-width:160px;border-radius:8px">
                        <?php else: ?>
                            <div id="main-image-empty" class="text-sm text-slate-500">
                                Brak. Upuść, wybierz lub wklej (Ctrl/Cmd+V) obraz…
                            </div>
                        <?php endif; ?>
                    </div>

                    <input id="mainImageFile" type="file" accept="image/*" class="hidden">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-light" id="btn-choose-file">📁 Wybierz plik…</button>
                        <span class="text-xs text-slate-500">Obsługa: PNG, JPG, WEBP · max 8 MB</span>
                    </div>
                </div>
            </div>
        </aside>
    </form>
</main>
<style>
    .input {
        width: 100%;
    }

    textarea.input {
        min-height: 260px;
        resize: vertical;
    }

    .chip-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .2rem .5rem;
        border-radius: 9999px;
        background: #eef2ff;
        border: 1px solid #e5e7eb;
        font-size: .8rem;
    }

    .chip-pill .x {
        cursor: pointer;
        font-weight: 700;
        padding: 0 .2rem;
    }

    .tag-suggest {
        position: absolute;
        z-index: 50;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: .25rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .08);
    }

    .tag-suggest button {
        display: block;
        width: 100%;
        text-align: left;
        padding: .35rem .5rem;
        border-radius: .5rem;
    }

    .tag-suggest button:hover {
        background: #f1f5f9;
    }
</style>

<style>
    .badge {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: .75rem;
        padding: .15rem .5rem;
        border-radius: 9999px;
        border: 1px solid rgba(0, 0, 0, .06)
    }

    .btn {
        border-radius: 10px;
        padding: .55rem .9rem;
        font-weight: 600;
        transition: .15s
    }

    .btn-primary {
        background: #2563eb;
        color: #fff
    }

    .btn-light {
        background: #f8fafc;
        border: 1px solid #e5e7eb
    }

    .input {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: .5rem .7rem;
        outline: 0
    }

    .input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .12)
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
        font-size: .8rem
    }

    .dropzone {
        transition: .15s
    }

    .dropzone.dragover {
        background: #f0f9ff;
        border-color: #38bdf8
    }
</style>

<script>
    // ——— skrót Ctrl/Cmd+S + ochrona przed utratą zmian ———
    (function() {
        var form = document.getElementById('product-form');
        var dirty = false;

        if (form) {
            form.addEventListener('input', function() {
                dirty = true;
            });
            window.addEventListener('beforeunload', function(e) {
                if (dirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
            form.addEventListener('submit', function() {
                dirty = false;
            });
        }
        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (form) form.requestSubmit();
            }
        });
    })();

    // ——— filtrowanie tagów ———
    (function() {
        var f = document.getElementById('tag-filter');
        var wrap = document.getElementById('tags-wrap');
        if (!f || !wrap) return;
        f.addEventListener('input', function() {
            var q = (f.value || '').trim().toLowerCase();
            wrap.querySelectorAll('.chip').forEach(function(ch) {
                var n = ch.getAttribute('data-name') || '';
                ch.style.display = n.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    })();

    // ——— szybka korekta stanu ———
    (function() {
        var minus = document.getElementById('btn-stock-minus');
        var plus = document.getElementById('btn-stock-plus');
        var modalBtn = document.getElementById('btn-stock-modal');

        function quickAdjust(delta) {
            fetch('/admin/products/api/stock_adjust.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include',
                body: JSON.stringify({
                    id: <?= (int)$product_id ?>,
                    delta: delta,
                    csrf_token: '<?= h($csrf) ?>'
                })
            }).then(r => r.json()).then(function(j) {
                if (j && j.ok) location.reload();
                else alert('❌ Korekta nieudana: ' + (j && j.error ? j.error : 'unknown'));
            }).catch(function(e) {
                alert('❌ Błąd: ' + e.message);
            });
        }
        if (minus) minus.addEventListener('click', function() {
            quickAdjust(-1);
        });
        if (plus) plus.addEventListener('click', function() {
            quickAdjust(1);
        });
        if (modalBtn) modalBtn.addEventListener('click', function() {
            var v = prompt('Podaj korektę (np. -5 lub 12):', '0');
            if (v === null) return;
            var d = parseFloat(v);
            if (isNaN(d) || !isFinite(d)) return alert('Nieprawidłowa liczba.');
            quickAdjust(d);
        });
    })();

    // ——— autosave szkicu (opcjonalnie) ———
    (function() {
        var form = document.getElementById('product-form');
        if (!form) return;
        var timer = null;
        form.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(function() {
                var fd = new FormData(form);
                fd.append('autosave', '1');
                fetch('/admin/products/api/product_autosave.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'include'
                }).catch(function() {
                    /* cicho */
                });
            }, 1200);
        });
    })();

    // ——— przeliczanie wagi kg->g przed submit ———
    (function() {
        var form = document.getElementById('product-form');
        if (!form) return;
        form.addEventListener('submit', function() {
            var el = form.querySelector('[name="weight_kg_ui"]');
            if (!el) return;
            var kg = parseFloat(el.value || '0');
            if (isNaN(kg) || !isFinite(kg)) return;
            var grams = Math.round(kg * 1000);
            var hidden = form.querySelector('input[name="weight_grams"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'weight_grams';
                form.appendChild(hidden);
            }
            hidden.value = String(grams);
        });
    })();

    // ——— prosty sparkline sprzedaży ———
    (function() {
        var c = document.getElementById('sales-spark');
        if (!c || !c.getContext) return;
        var ctx = c.getContext('2d'),
            W = c.width,
            H = c.height;
        ctx.clearRect(0, 0, W, H);
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#2563eb';
        ctx.beginPath();
        var points = 24,
            base = H * 0.6;
        for (var i = 0; i < points; i++) {
            var x = (W / (points - 1)) * i;
            var y = base + Math.sin(i * 0.6) * 8 + (Math.random() * 6 - 3);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.stroke();
    })();

    // ====== UPLOAD/PASTE/DRAG obrazka głównego (jeden tor) ======
    (function() {
        var dropzone = document.getElementById('image-dropzone');
        var chooseBtn = document.getElementById('btn-choose-file');
        var fileInput = document.getElementById('mainImageFile');
        var preview = document.getElementById('img-preview');

        async function doUpload(file) {
            var fd = new FormData();
            fd.append('csrf_token', '<?= h($csrf) ?>');
            fd.append('product_id', '<?= (int)$product_id ?>');
            fd.append('mode', 'main');
            fd.append('file', file, file.name || 'image');

            const res = await fetch('/admin/products/api/image_upload.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const j = await res.json();
            if (!j || !j.ok) throw new Error((j && j.error) || 'upload_failed');

            // odśwież preview (bez przeładowania)
            if (preview && j.url) preview.src = j.url;
            var empty = document.getElementById('main-image-empty');
            if (empty) empty.style.display = 'none';
        }

        function uploadFile(file) {
            if (!file) return;
            if (!/^image\//.test(file.type)) return alert('To nie wygląda na obrazek.');
            if (file.size > 8 * 1024 * 1024) return alert('Plik zbyt duży (max 8 MB).');
            doUpload(file).catch(e => alert('❌ Upload nieudany: ' + e.message));
        }

        if (chooseBtn && fileInput) {
            chooseBtn.addEventListener('click', function() {
                fileInput.click();
            });
            fileInput.addEventListener('change', function() {
                if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
            });
        }

        // drag&drop
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }));
            ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }));
            dropzone.addEventListener('drop', function(e) {
                var dt = e.dataTransfer;
                if (dt && dt.files && dt.files[0]) uploadFile(dt.files[0]);
            });
        }

        // paste (Ctrl/Cmd+V)
        window.addEventListener('paste', function(e) {
            var items = (e.clipboardData && e.clipboardData.items) || [];
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                if (it.kind === 'file') {
                    var f = it.getAsFile();
                    if (f) {
                        uploadFile(f);
                        e.preventDefault();
                        return;
                    }
                }
            }
        });
    })();
</script>
<script>
    // ——— AI: Generowanie opisu (cache-first przez ajax_generate_desc.php) ———
    (function() {
        var btn = document.getElementById('btn-ai-desc');
        var ta = document.getElementById('desc-textarea');
        var status = document.getElementById('ai-desc-status');
        var form = document.getElementById('product-form');

        if (!btn || !ta || !form) return;

        function getVal(sel) {
            var el = form.querySelector(sel);
            return (el && el.value != null) ? String(el.value).trim() : '';
        }

        function getSelectedTagLabels() {
            // pobieramy labelki, żeby AI dostało sensowne nazwy (fallback: id)
            var out = [];
            form.querySelectorAll('input[name="tags[]"]:checked').forEach(function(cb) {
                var label = cb.closest('label');
                var txt = (label ? label.textContent : cb.value) || '';
                out.push(txt.trim());
            });
            return out;
        }

        btn.addEventListener('click', async function() {
            var payload = {
                name: getVal('input[name="name"]'),
                code: getVal('input[name="code"]'),
                twelve_nc: getVal('input[name="twelve_nc"]'), // jeżeli nie masz pola, zostanie '' – OK
                price: getVal('input[name="unit_price"]'),
                vat_rate: getVal('input[name="vat_rate"]') || 23,
                tags: getSelectedTagLabels()
            };

            if (!payload.name) {
                alert('Podaj najpierw nazwę produktu.');
                return;
            }

            if (ta.value.trim().length && !confirm('Zastąpić istniejący opis treścią z AI?')) {
                return;
            }

            btn.disabled = true;
            status.textContent = 'Generuję…';

            try {
                var res = await fetch('/admin/products/ajax_generate_desc.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify(payload)
                });
                var json = await res.json();

                ta.value = (json && json.description) ? json.description : '';
                status.textContent = 'Gotowe ✅';
            } catch (e) {
                console.error(e);
                status.textContent = 'Błąd ❌';
                alert('Nie udało się wygenerować opisu.');
            } finally {
                btn.disabled = false;
                setTimeout(function() {
                    status.textContent = '';
                }, 1500);
            }
        });
    })();
</script>

<script>
    (function() {
        var input = document.getElementById('tag-quick-input'); // małe pole "Wpisz tag i Enter…"
        var chips = document.getElementById('tag-chips'); // miejsce na "chipsy" nowych nazw
        var saveBtn = document.getElementById('btn-save-tags'); // przycisk "Zapisz tagi"
        var status = document.getElementById('tags-status'); // mały status przy przycisku
        var form = document.getElementById('product-form');

        if (!input || !chips || !saveBtn || !form) return;

        // pełna lista tagów (do podpowiedzi) — wygenerowana w PHP
        var ALL_TAGS = <?= json_encode(
                            array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color']], $all_tags),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ) ?>;

        // bieżące tagi produktu — z getWithTags (prefill chipsów)
        var CURRENT = <?= json_encode(
                            array_values(array_map(fn($t) => ['name' => $t['name']], $product['tags'] ?? [])),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ) ?>;

        // stan: set nazw (case-insensitive)
        var set = new Map(); // key(lower) -> displayName

        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [c]));
        }

        function addTagName(name) {
            name = (name || '').trim();
            if (!name) return;
            var key = name.toLowerCase();
            if (set.has(key)) return;
            set.set(key, name);
            render();
        }

        function removeTagName(name) {
            set.delete((name || '').toLowerCase());
            render();
        }

        function render() {
            chips.innerHTML = '';
            set.forEach(function(display) {
                var el = document.createElement('span');
                el.className = 'chip-pill';
                el.innerHTML = '<span>' + escapeHtml(display) + '</span><span class="x" title="Usuń">×</span>';
                el.querySelector('.x').addEventListener('click', function() {
                    removeTagName(display);
                });
                chips.appendChild(el);
            });
        }

        // Prefill nazwami aktualnych tagów
        (CURRENT || []).forEach(function(t) {
            addTagName(String(t.name || '').trim());
        });

        // parse po Enter/przecinek
        function commitInput() {
            var s = input.value || '';
            s.split(',').forEach(function(part) {
                addTagName(part);
            });
            input.value = '';
            hideSuggest();
        }

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                commitInput();
            } else if (e.key === 'Escape') {
                hideSuggest();
            }
        });

        // prosta podpowiedź
        var suggestBox;

        function showSuggest(items) {
            hideSuggest();
            if (!items || !items.length) return;
            suggestBox = document.createElement('div');
            suggestBox.className = 'tag-suggest';
            items.slice(0, 8).forEach(function(it) {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = it.name;
                b.addEventListener('click', function() {
                    addTagName(it.name);
                    input.value = '';
                    hideSuggest();
                });
                suggestBox.appendChild(b);
            });
            var r = input.getBoundingClientRect();
            suggestBox.style.left = r.left + 'px';
            suggestBox.style.top = (r.bottom + window.scrollY + 4) + 'px';
            suggestBox.style.minWidth = r.width + 'px';
            document.body.appendChild(suggestBox);
        }

        function hideSuggest() {
            if (suggestBox) {
                suggestBox.remove();
                suggestBox = null;
            }
        }
        input.addEventListener('input', function() {
            var q = (input.value || '').trim().toLowerCase();
            if (!q) {
                hideSuggest();
                return;
            }
            var items = ALL_TAGS.filter(function(t) {
                return t.name.toLowerCase().indexOf(q) !== -1 && !set.has(t.name.toLowerCase());
            });
            showSuggest(items);
        });
        document.addEventListener('click', function(e) {
            if (suggestBox && !suggestBox.contains(e.target) && e.target !== input) hideSuggest();
        });

        // ZAPIS
        saveBtn.addEventListener('click', async function() {

            // 1) nazwy z chipsów
            var names = Array.from(set.values()).filter(Boolean);

            // 2) ID z checkboxów „Zaawansowane”
            var chIds = [];
            document.querySelectorAll('#tags-wrap input[name="tags[]"]:checked').forEach(function(cb) {
                var v = parseInt(cb.value, 10) || 0;
                if (v > 0) chIds.push(v);
            });

            if (!names.length && !chIds.length) {
                if (!confirm('Usunąć wszystkie tagi z produktu?')) return;
            }

            saveBtn.disabled = true;
            status.textContent = 'Zapisuję…';

            try {
                const res = await fetch('/admin/products/api/tags_upsert.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        csrf_token: '<?= h($csrf) ?>',
                        product_id: <?= (int)$product_id ?>,
                        tags: names, // po NAZWIE utworzy brakujące
                        tag_ids: chIds // plus te z checkboxów
                    })
                });
                const j = await res.json();
                if (!j || !j.ok) throw new Error(j && j.error ? j.error : 'save_failed');

                status.textContent = 'Zapisano ✅';

                // odśwież checkboxy (zaznacz zestaw, który realnie poszedł do linków)
                var ids = new Set((j.ids || []).map(function(x) {
                    return parseInt(x, 10) || 0;
                }));
                document.querySelectorAll('#tags-wrap input[name="tags[]"]').forEach(function(cb) {
                    var id = parseInt(cb.value, 10) || 0;
                    cb.checked = ids.has(id);
                });

                // dorysuj ewentualnie nowo utworzone tagi do „Zaawansowanych”
                if (j.tags && j.tags.length) {
                    var wrap = document.getElementById('tags-wrap');
                    var existingIds = new Set(Array.from(wrap.querySelectorAll('input[name="tags[]"]')).map(function(cb) {
                        return parseInt(cb.value, 10) || 0;
                    }));
                    j.tags.forEach(function(t) {
                        if (existingIds.has(t.id)) return;
                        var lbl = document.createElement('label');
                        lbl.className = 'chip';
                        lbl.setAttribute('data-name', (t.name || '').toLowerCase());
                        lbl.innerHTML = '<input type="checkbox" name="tags[]" value="' + t.id + '" checked> ' +
                            '<span class="w-2 h-2 rounded-full" style="background:' + (t.color || '#666') + '"></span> ' +
                            escapeHtml(t.name || '');
                        wrap.appendChild(lbl);
                    });
                }

                setTimeout(function() {
                    status.textContent = '';
                }, 1500);
            } catch (e) {
                console.error(e);
                status.textContent = 'Błąd ❌';
                alert('Nie udało się zapisać tagów: ' + (e && e.message ? e.message : ''));
            } finally {
                saveBtn.disabled = false;
            }
        });
    })();
</script>


<script>
    (function() {
        var input = document.getElementById('new-tag-input');
        var chipsWrap = document.getElementById('new-tags-chips');
        var hidden = document.getElementById('tags_tokens');
        var form = document.getElementById('product-form');
        if (!input || !chipsWrap || !hidden || !form) return;

        var tokens = new Set();

        function norm(s) {
            return (s || '').trim().toLowerCase().replace(/\s+/g, ' ').slice(0, 64);
        }

        function render() {
            chipsWrap.innerHTML = '';
            Array.from(tokens).forEach(function(t) {
                var chip = document.createElement('span');
                chip.className = 'chip';
                chip.textContent = t;
                var x = document.createElement('button');
                x.type = 'button';
                x.textContent = '×';
                x.style.marginLeft = '6px';
                x.onclick = function() {
                    tokens.delete(t);
                    render();
                };
                chip.appendChild(x);
                chipsWrap.appendChild(chip);
            });
            hidden.value = Array.from(tokens).join(',');
        }

        function addFromString(s) {
            (s || '').split(',').forEach(function(p) {
                var v = norm(p);
                if (v) tokens.add(v);
            });
            render();
        }

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addFromString(input.value);
                input.value = '';
            }
        });

        input.addEventListener('blur', function() {
            if (input.value.trim()) {
                addFromString(input.value);
                input.value = '';
            }
        });

        form.addEventListener('submit', function() {
            // upewnij się, że pole hidden jest aktualne
            hidden.value = Array.from(tokens).join(',');
        });
    })();
</script>


<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>