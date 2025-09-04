<?php
// admin/live/ajax/ajax_live_temp_list.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php';

[$owner_id, $live_id] = ctx();
$format = $_GET['format'] ?? 'html';

if ($owner_id <= 0 || $live_id <= 0) {
    json_err('Brak kontekstu (owner_id/live_id).', 422);
}

/*
  Agregacja per klient:
  - pending_qty / sent_qty / pending_items / sent_items
  - pending_brutto / sent_brutto  (lt.price || p.price) * qty
  - last_sent_at
*/
$sql = <<<SQL
SELECT
  t.client_id,
  t.client_name AS name,
  t.pending_qty,
  t.sent_qty,
  t.pending_items,
  t.sent_items,
  t.pending_brutto,
  t.sent_brutto,
  t.last_sent_at
FROM (
  SELECT
    lt.client_id,
    COALESCE(c.name, CONCAT('Klient #', lt.client_id)) AS client_name,
    SUM(CASE WHEN lt.transferred_at IS NULL THEN lt.qty ELSE 0 END) AS pending_qty,
    SUM(CASE WHEN lt.transferred_at IS NOT NULL THEN lt.qty ELSE 0 END) AS sent_qty,
    SUM(CASE WHEN lt.transferred_at IS NULL THEN 1 ELSE 0 END) AS pending_items,
    SUM(CASE WHEN lt.transferred_at IS NOT NULL THEN 1 ELSE 0 END) AS sent_items,
    SUM(CASE WHEN lt.transferred_at IS NULL
             THEN lt.qty * COALESCE(lt.price, p.unit_price, 0)
             ELSE 0 END) AS pending_brutto,
    SUM(CASE WHEN lt.transferred_at IS NOT NULL
             THEN lt.qty * COALESCE(lt.price, p.unit_price, 0)
             ELSE 0 END) AS sent_brutto,
    MAX(lt.transferred_at) AS last_sent_at
  FROM live_temp lt
  LEFT JOIN clients c
    ON c.id = lt.client_id
   AND (c.owner_id = lt.owner_id OR c.owner_id IS NULL)
  LEFT JOIN products p
    ON p.id = lt.product_id
   AND p.owner_id = lt.owner_id
  WHERE lt.owner_id = :owner_id
    AND lt.live_id  = :live_id
  GROUP BY lt.client_id, client_name
) AS t
ORDER BY
  t.pending_items DESC,
  (t.last_sent_at IS NULL) ASC,
  t.last_sent_at DESC,
  t.client_name ASC
SQL;

try {
    $st = $pdo->prepare($sql);
    $st->execute(['owner_id' => $owner_id, 'live_id' => $live_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    json_err('DB error: ' . $e->getMessage(), 500);
}

// flaga all_sent
foreach ($rows as &$r) {
    $r['all_sent'] = ((int)$r['pending_items'] === 0 && (int)$r['sent_items'] > 0) ? 1 : 0;
}
unset($r);

if ($format === 'json') {
    json_ok(['success' => true, 'clients' => $rows]);
}

if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');

function zloty($n): string {
    return number_format((float)$n, 2, ',', ' ') . ' zł';
}
?>
<div id="liveClientList" class="space-y-3">
  <?php if (empty($rows)): ?>
    <div class="text-gray-500">Brak przypisanych klientów.</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $pendingItems  = (int)$r['pending_items'];
        $sentItems     = (int)$r['sent_items'];
        $pendingQty    = (int)$r['pending_qty'];
        $sentQty       = (int)$r['sent_qty'];
        $pendingBrutto = (float)$r['pending_brutto'];
        $sentBrutto    = (float)$r['sent_brutto'];

        $badge =
          $pendingItems > 0
            ? "<span class='ml-2 align-middle text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800'>Do wysłania: {$pendingItems}</span>"
            : ($sentItems > 0
                ? "<span class='ml-2 align-middle text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700'>Wysłane: {$sentItems}</span>"
                : "");
      ?>
      <details class="group rounded-2xl border border-slate-200 overflow-hidden"
               data-client-id="<?= (int)$r['client_id'] ?>"
               <?= $pendingItems > 0 ? 'open' : '' ?>>
        <summary class="px-4 py-3 cursor-pointer select-none hover:bg-slate-50">
          <div class="flex items-center justify-between w-full gap-4">
            <div class="font-medium text-slate-800 truncate">
              <?= htmlspecialchars($r['name'] ?? ('Klient #'.$r['client_id'])) ?>
              <?= $badge ?>
            </div>
            <!-- PODSUMOWANIE W NAGŁÓWKU -->
            <div class="text-[12px] text-slate-600 flex items-center gap-4">
              <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1">
                  🟡 <b><?= $pendingQty ?></b>
                </span>
                <span class="inline-flex items-center gap-1">
                  ✅ <b><?= $sentQty ?></b>
                </span>
              </div>
              <div class="hidden md:flex items-center gap-3">
                <span class="inline-flex items-center gap-1">
                  💰 pending: <b><?= zloty($pendingBrutto) ?></b>
                </span>
                <span class="inline-flex items-center gap-1">
                  💰 wysłane: <b><?= zloty($sentBrutto) ?></b>
                </span>
              </div>
              <svg class="h-5 w-5 text-slate-400 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
              </svg>
            </div>
          </div>
        </summary>

        <!-- Treść klienta dociągana AJAX-em -->
        <div class="px-4 pb-4 pt-2 bg-white">
          <div class="client-items text-sm text-slate-600" data-client-items="1">
            <div class="text-xs text-slate-400">Ładuję pozycje…</div>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
