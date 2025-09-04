<?php
// admin/live/view.php — Olaj.pl V4
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/top_panel.php';
require_once __DIR__ . '/../../layout/layout_header.php';

$owner_id    = $_SESSION['user']['owner_id'] ?? 0;
$operator_id = $_SESSION['user']['id'] ?? 0;
$stream_id   = (int)($_GET['id'] ?? 0);

// Pobranie transmisji
$stream = null;
if ($stream_id) {
  $st = $pdo->prepare("SELECT id, owner_id, title, status, started_at, ended_at FROM live_streams WHERE id=? AND owner_id=? LIMIT 1");
  $st->execute([$stream_id, $owner_id]);
  $stream = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$stream) {
  echo '<div class="max-w-3xl mx-auto p-6">Nie znaleziono transmisji.</div>';
  require_once __DIR__ . '/../../layout/layout_footer.php';
  exit;
}
?>
<style>
  /* Proste klasy bez Tailwind @apply — działa od ręki */
  .card {
    background: #fff;
    border: 1px solid #f1f5f9;
    border-radius: 16px;
    box-shadow: 0 1px 2px rgba(16, 24, 40, .04)
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    border-radius: 12px;
    padding: .5rem 1rem;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #374151
  }

  .btn-primary {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff
  }

  .btn-primary:hover {
    background: #15803d
  }

  .btn-ghost {
    background: #fff
  }

  .btn-ghost:hover {
    background: #f3f4f6
  }

  .btn-soft {
    background: #f3f4f6
  }

  .btn-soft:hover {
    background: #e5e7eb
  }

  .badge {
    display: inline-flex;
    align-items: center;
    border-radius: 9999px;
    padding: .125rem .5rem;
    font-size: .75rem;
    font-weight: 600
  }

  .badge-ok {
    background: #ecfdf5;
    color: #065f46
  }

  .badge-warn {
    background: #fffbeb;
    color: #92400e
  }

  .badge-err {
    background: #fef2f2;
    color: #991b1b
  }

  .skeleton {
    animation: pulse 1.5s ease-in-out infinite;
    background: #f3f4f6;
    border-radius: 8px
  }

  @keyframes pulse {
    0% {
      opacity: 1
    }

    50% {
      opacity: .5
    }

    100% {
      opacity: 1
    }
  }

  .sticky-top {
    position: sticky;
    top: 0;
    z-index: 40;
    background: rgba(255, 255, 255, .8);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid #f1f5f9
  }

  .offcanvas {
    transition: transform .25s ease
  }

  .offcanvas.hidden {
    transform: translateX(100%)
  }

  /* Select2 „bez kreski” */
  .select2-container .select2-selection--single {
    height: 40px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    padding: 0 .75rem;
    background: #fff
  }

  .select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 38px
  }

  .select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 38px;
    right: 8px
  }

  /* Komentarz – stała szerokość 100px */
  .comment-input {
    width: 100px;
  }

  /* (opcjonalnie) klient Select2, żeby nie był 20px */
  #clientRows .select2-container {
    min-width: 220px;
  }
</style>

<div class="sticky-top">
  <div class="max-w-[1500px] mx-auto px-4 py-3 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="text-2xl">🎥</div>
      <div>
        <div class="text-lg font-semibold leading-tight"><?= htmlspecialchars($stream['title'] ?? ('LIVE #' . $stream_id)) ?></div>
        <div class="text-xs text-gray-500">ID: <?= (int)$stream_id ?> •
          <?php if (($stream['status'] ?? '') === 'live'): ?>
            <span class="badge badge-err">LIVE</span>
          <?php elseif (($stream['status'] ?? '') === 'planned'): ?>
            <span class="badge badge-warn">Planned</span>
          <?php else: ?>
            <span class="badge">Ended</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button id="btnRefresh" class="btn btn-ghost" type="button" title="Odśwież (r)">↻ Odśwież</button>
      <button id="btnFilters" class="btn btn-ghost" type="button" title="Filtry (f)">🧰 Filtry</button>
      <div class="relative">
        <input id="globalSearch" placeholder="Szukaj (Ctrl+/)" class="border rounded-xl px-3 py-2 w-64 focus:outline-none focus:ring focus:ring-emerald-200" />
      </div>
      <button id="btnFinalize" class="btn btn-primary" type="button">Wyślij podsumowania</button>
    </div>
  </div>
</div>

<main class="w-full mx-auto px-4 py-6 grid grid-cols-1 xl:grid-cols-[minmax(350px,1fr)_390px] xl:grid-rows-[auto_1fr] gap-6">
  <!-- Lewa kolumna -->
  <section id="addPanel" class="space-y-6 flex-grow">
    <div class="card p-4">
      <h2 class="font-semibold mb-3">➕ Dodaj produkt</h2>
      <form id="addProductForm" class="space-y-4">
        <input type="hidden" name="live_id" value="<?= (int)$stream_id ?>">
        <input type="hidden" name="owner_id" value="<?= (int)$owner_id ?>">
        <input type="hidden" name="operator_user_id" value="<?= (int)$operator_id ?>">

        <!-- A) Produkt (katalog/custom) -->
        <div class="space-y-2">
          <label class="block text-sm font-medium">Produkt (katalog)</label>
          <select id="product_search" name="product_id" class="w-60 select2-product">
            <option value=""></option>
          </select>
          <div class="text-xs text-gray-500 flex items-center gap-2">
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" id="toggleCustom" /> Produkt niestandardowy
            </label>
            <span id="availabilityBadge" class="badge badge-warn hidden">Sprawdzam dostępność…</span>
          </div>
        </div>

        <div id="customFields" class="grid grid-cols-2 gap-3 hidden">
          <div class="col-span-2">
            <label class="block text-sm font-medium">Nazwa</label>
            <input type="text" name="name" class="w-full border rounded-xl px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium">SKU (opc.)</label>
            <input type="text" name="sku" class="w-full border rounded-xl px-3 py-2" />
          </div>
          <div>
            <label class="block text-sm font-medium">Cena (PLN)</label>
            <input type="number" step="0.01" min="0" name="price" class="w-full border rounded-xl px-3 py-2 text-right" />
          </div>
          <div>
            <label class="block text-sm font-medium">VAT</label>
            <select name="vat_rate" class="w-full border rounded-xl px-3 py-2">
              <option value="23">23%</option>
              <option value="8">8%</option>
              <option value="5">5%</option>
              <option value="0">0%</option>
            </select>
          </div>
        </div>

        <!-- B) Klienci -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <label class="block text-sm font-medium">Klienci</label>
            <div class="text-xs text-gray-500">Możesz dodać wielu naraz</div>
          </div>
          <div id="clientRows" class="space-y-2"></div>
          <button id="btnAddClientRow" type="button" class="btn btn-soft w-full">+ Dodaj klienta</button>
        </div>

        <!-- C) Podsumowanie + akcje -->
        <div class="flex items-center justify-between pt-2">
          <div class="text-sm text-gray-600">
            Razem: <span id="sumClients">0</span> klient(y) × <span id="sumQty">0</span> szt = <span id="sumUnits">0</span> szt
          </div>
          <div class="flex items-center gap-2">
            <button type="button" id="btnClearForm" class="btn btn-ghost">Wyczyść</button>
            <button type="submit" class="btn btn-primary">Dodaj</button>
          </div>
        </div>
      </form>
    </div>
    <div class="card p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold">👥 Klienci z przypisanymi produktami</h2>
        <div class="flex items-center gap-2 text-sm">
          <select id="fltStatus" class="border rounded-xl px-2 py-1">
            <option value="">Status: wszystkie</option>
            <option value="nowe">nowe</option>
            <option value="otwarta_paczka">otwarta_paczka*</option>
          </select>
          <select id="fltSource" class="border rounded-xl px-2 py-1">
            <option value="">Źródło: wszystkie</option>
            <option value="catalog">katalog</option>
            <option value="custom">custom</option>
          </select>
          <select id="fltRes" class="border rounded-xl px-2 py-1">
            <option value="">Rezerwacje: wszystkie</option>
            <option value="reserved">reserved</option>
            <option value="committed">committed</option>
            <option value="released">released</option>
          </select>
        </div>
      </div>
      <div id="clientAccordion" class="space-y-3">
        <div class="skeleton" style="height:64px"></div>
        <div class="skeleton" style="height:64px"></div>
        <div class="skeleton" style="height:64px"></div>
      </div>
    </div>

    <div class="card p-4">
      <h3 class="font-semibold mb-2">📊 Szybkie statystyki</h3>
      <div id="quickStats" class="grid grid-cols-2 gap-3 text-sm">
        <div class="p-3 border rounded-xl">
          <div class="text-gray-500">Pozycji w live_temp</div>
          <div class="text-lg font-semibold" id="statItems">—</div>
        </div>
        <div class="p-3 border rounded-xl">
          <div class="text-gray-500">Aktywnych rezerwacji</div>
          <div class="text-lg font-semibold" id="statRes">—</div>
        </div>
      </div>
    </div>
  </section>

  <!-- Prawa kolumna -->
<section id="assignedList" class="space-y-6">


<aside id="commentsCard"
  class="card p-4 w-[390px] sticky top-[84px] self-start h-[calc(100vh-100px)] overflow-auto">
  <h2 class="font-semibold mb-3">💬 Komentarze LIVE</h2>
  <div id="commentList" class="space-y-3 text-sm text-gray-700">
    <div class="text-gray-400">(W przygotowaniu) — placeholder i hook JS.</div>
  </div>
  <div class="mt-3 flex items-center gap-2">
    <input id="commentInput" class="border rounded-xl px-3 py-2 flex-1" placeholder="Dodaj komentarz (Enter)" />
    <button id="btnAddComment" class="btn btn-soft" type="button">Wyślij</button>
  </div>
</aside>
  </section>
</main>

<!-- Offcanvas filtry -->
<aside id="filtersOffcanvas" class="offcanvas fixed top-0 right-0 w-[360px] h-full bg-white border-l border-gray-200 p-4 hidden">
  <div class="flex items-center justify-between mb-2">
    <h3 class="font-semibold">🧰 Filtry</h3>
    <button id="btnCloseFilters" class="btn btn-ghost">✖</button>
  </div>
  <div class="space-y-4 text-sm">
    <div>
      <label class="block text-xs text-gray-500">Zakres dat</label>
      <input id="fltDateFrom" type="datetime-local" class="w-full border rounded-xl px-2 py-1" />
      <input id="fltDateTo" type="datetime-local" class="w-full border rounded-xl px-2 py-1 mt-1" />
    </div>
    <div>
      <label class="block text-xs text-gray-500">Braki magazynowe</label>
      <select id="fltOos" class="w-full border rounded-xl px-2 py-1">
        <option value="">Wszystko</option>
        <option value="1">Tylko z brakami</option>
        <option value="0">Tylko dostępne</option>
      </select>
    </div>
    <button id="btnApplyFilters" class="btn btn-primary w-full" type="button">Zastosuj</button>
  </div>
</aside>

<!-- Modal finalizacji -->
<div id="finalizeModal" class="fixed inset-0 bg-black/30 hidden items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-4">
    <h3 class="font-semibold text-lg">Finalizować wszystkie pozycje?</h3>
    <p class="text-sm text-gray-600 mt-1">Operacja jest <strong>all-or-nothing</strong>. Jeśli chociaż jedna pozycja nie przejdzie, całość zostanie cofnięta.</p>
    <div id="finalizeErrors" class="hidden mt-2 text-sm text-rose-600"></div>
    <div class="mt-4 flex items-center justify-end gap-2">
      <button id="btnCancelFinalize" class="btn btn-ghost" type="button">Anuluj</button>
      <button id="btnConfirmFinalize" class="btn btn-primary" type="button">Tak, finalizuj</button>
    </div>
  </div>
</div>

<!-- Toasty -->
<div id="toastWrap" class="fixed top-4 right-4 space-y-2 z-50"></div>

<!-- Biblioteki: jQuery + Select2 ZANIM live.js (z CDNem jako pewniak) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>

<!-- Twój skrypt modułu -->
<script src="js/live.js?v=1.0.1"></script>

<!-- Guard: poczekaj aż Select2 będzie gotowy (gdyby CDN zlagował) -->
<script>
  (function() {
    function whenSelect2Ready(cb) {
      if (window.jQuery && jQuery.fn && jQuery.fn.select2) return cb();
      const t = setInterval(() => {
        if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
          clearInterval(t);
          cb();
        }
      }, 100);
      setTimeout(() => clearInterval(t), 5000);
    }
    document.addEventListener('DOMContentLoaded', function() {
      whenSelect2Ready(function() {
        // Jeżeli live.js nie zrobił startu na DOMContentLoaded, można by tu awaryjnie zainicjalizować,
        // ale wg naszego live.js nie trzeba — zostawiamy jako guard.
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>