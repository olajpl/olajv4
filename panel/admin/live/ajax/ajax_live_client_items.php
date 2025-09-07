<?php
// admin/live/ajax/ajax_live_client_items.php
declare(strict_types=1);

require_once __DIR__ . '/__live_boot.php';

[$owner_id, $live_id] = ctx();
$client_id = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);

if ($owner_id <= 0 || $live_id <= 0 || $client_id <= 0) {
    json_err('Brak kontekstu (owner/live/client).', 422);
}

$sql = <<<SQL
SELECT
  lt.id                                              AS row_id,
  lt.source_type,
  lt.product_id,
  COALESCE(NULLIF(TRIM(lt.name), ''), p.name, CONCAT('ID:', lt.product_id)) AS name,
  COALESCE(NULLIF(TRIM(lt.sku), ''),  p.sku, p.code)                         AS sku,
  lt.qty,
  COALESCE(lt.price, p.unit_price)                                          AS price,
  COALESCE(lt.vat_rate, p.vat_rate)                                         AS vat_rate,
  lt.note,
  lt.transferred_at,
  lt.reservation_id
FROM live_temp lt
LEFT JOIN products p
  ON p.id = lt.product_id
 AND p.owner_id = lt.owner_id
WHERE lt.owner_id  = :owner_id
  AND lt.live_id   = :live_id
  AND lt.client_id = :client_id
ORDER BY
  (lt.transferred_at IS NOT NULL) ASC,
  lt.transferred_at DESC,
  lt.id DESC
SQL;


$stmt = $pdo->prepare($sql);
$stmt->execute([
    'owner_id'  => $owner_id,
    'live_id'   => $live_id,
    'client_id' => $client_id
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');

function zloty($n): string { return number_format((float)$n, 2, ',', ' ') . ' zł'; }
?>
<div class="space-y-2">
  <?php if (empty($rows)): ?>
    <div class="text-xs text-slate-500">Brak pozycji dla tego klienta.</div>
  <?php else: ?>
    <div class="grid grid-cols-12 text-[12px] font-medium text-slate-500 px-2">
      <div class="col-span-6">Produkt</div>
      <div class="col-span-2 text-right">Ilość</div>
      <div class="col-span-2 text-right">Cena</div>
      <div class="col-span-2 text-right">Status</div>
    </div>

    <?php foreach ($rows as $r):
      $isPending = ($r['transferred_at'] === null);
      $statusLbl = $isPending ? 'Do wysłania' : 'Wysłane';
      $statusCls = $isPending
        ? 'bg-amber-50 text-amber-700 border-amber-100'
        : 'bg-emerald-50 text-emerald-700 border-emerald-100';
      $name = trim((string)($r['name'] ?? '')) ?: '[bez nazwy]';

      $sku  = trim((string)($r['sku']  ?? ''));
      $when = $r['transferred_at'] ? date('Y-m-d H:i', strtotime((string)$r['transferred_at'])) : '';
      $qty  = (int)($r['qty'] ?? 0);
      $price = (float)($r['price'] ?? 0);
      $vat   = (float)($r['vat_rate'] ?? 0);
    ?>
      <div class="grid grid-cols-12 items-center gap-2 border rounded-xl px-2 py-2">
        <div class="col-span-6">
          <div class="font-medium text-slate-800"><?= htmlspecialchars($name) ?></div>
          <div class="text-[12px] text-slate-500">
            <?php if ($sku): ?>SKU: <?= htmlspecialchars($sku) ?> • <?php endif; ?>
            VAT: <?= $vat ?>%
            <?php if (!$isPending && $when): ?> • <?= htmlspecialchars($when) ?><?php endif; ?>
          </div>
        </div>

        <div class="col-span-2 text-right">
          <?php if ($isPending): ?>
            <input type="number" min="1" value="<?= $qty ?>"
              class="w-16 text-right border rounded-lg px-2 py-1"
              data-qty-input data-row-id="<?= (int)$r['row_id'] ?>">
          <?php else: ?>
            <span class="inline-block w-16 text-right"><?= $qty ?></span>
          <?php endif; ?>
        </div>

        <div class="col-span-2 text-right">
          <?= zloty($price) ?>
        </div>

        <div class="col-span-2">
          <div class="flex items-center justify-end gap-2">
            <span class="inline-flex items-center text-[12px] px-2 py-1 border rounded-full <?= $statusCls ?>">
              <?= $statusLbl ?>
            </span>
            <?php if ($isPending): ?>
              <button type="button"
                      class="text-[12px] text-rose-600 hover:underline"
                      title="Usuń pozycję"
                      data-del-id="<?= (int)$r['row_id'] ?>">Usuń</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
