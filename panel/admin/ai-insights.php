<?php
// admin/ai-insights.php – Panel AI (Baza V2 safe, engine-friendly)
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/log.php';
include __DIR__ . '/../layout/top_panel.php';
include __DIR__ . '/../layout/layout_header.php';

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

/** Guard: jeśli tabela nie istnieje, pokaż ładny empty state zamiast 500 */
$hasAiReports = false;
try {
  $chk = $pdo->query("SHOW TABLES LIKE 'ai_reports'");
  $hasAiReports = (bool)$chk->rowCount();
} catch (Throwable $e) {
  // zignoruj – i tak pokażemy empty state
}

if (!$hasAiReports): ?>
  <a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
    <span class="text-lg">←</span> Wróć
  </a>
  <h1 class="text-2xl font-bold mb-4">🧠 Raporty AI</h1>
  <div class="border rounded p-6 bg-white text-gray-600">
    Moduł <code>ai_reports</code> nie jest jeszcze zainstalowany. Utwórz tabelę i odśwież stronę.
  </div>
  <?php include __DIR__ . '/../layout/layout_footer.php';
  exit; ?>
<?php endif; ?>

<?php
// Ujednolicone SELECT pod obecną strukturę: context, scope, ref_id, insights_json/metrics_json/content
// Dodatkowo zwracamy aliasy context_type/context_id, jeśli kiedyś były używane w widoku.
$sql = "
  SELECT
    id,
    owner_id,
    context,                 -- np. 'dashboard','orders','products','live','cw',...
    scope,                   -- np. 'global','order','group','product','client','custom'
    ref_id,
    COALESCE(insights_json, metrics_json, JSON_OBJECT()) AS data_json,
    created_at,
    /* aliasy zgodności wstecznej */
    scope   AS context_type,
    ref_id  AS context_id
  FROM ai_reports
  WHERE owner_id = :owner_id AND (deleted_at IS NULL)
  ORDER BY created_at DESC, id DESC
  LIMIT 100
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['owner_id' => $ownerId]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<a href="../" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
  <span class="text-lg">←</span> Wróć
</a>
<h1 class="text-2xl font-bold mb-4">🧠 Raporty AI</h1>

<?php if (!$reports): ?>
  <div class="border rounded p-6 bg-white text-gray-600">Brak raportów dla tego konta.</div>
<?php endif; ?>

<div class="grid gap-4">
  <?php foreach ($reports as $report): ?>
    <?php
    // data_json to kolumna JSON – PDO zwróci string; dekodujemy bezpiecznie:
    $data = [];
    if (isset($report['data_json'])) {
      $tmp = json_decode((string)$report['data_json'], true);
      if (is_array($tmp)) $data = $tmp;
    }

    // Preferuj nowe pola: context/ref_id; aliasy jako fallback.
    $ctx = (string)($report['context'] ?? $report['context_type'] ?? '');
    $cid = (int)($report['ref_id'] ?? $report['context_id'] ?? 0);

    // Etykieta nagłówka na podstawie context
    if ($ctx === 'live') {
      $title = "🎥 Transmisja #{$cid}";
    } elseif ($ctx === 'order') {
      $title = "📦 Zamówienie #{$cid}";
    } elseif ($ctx === 'products' || $ctx === 'product') {
      $title = $cid > 0 ? "🛒 Produkt #{$cid}" : "🛒 Produkty";
    } elseif ($ctx === 'clients' || $ctx === 'client') {
      $title = $cid > 0 ? "👤 Klient #{$cid}" : "👤 Klienci";
    } elseif ($ctx === 'cw') {
      $title = "✉️ CW (komunikacja)" . ($cid ? " #{$cid}" : "");
    } else {
      $title = $cid ? "🗂️ Kontekst #{$cid}" : "🗂️ Raport";
    }
    ?>
    <div class="border rounded p-4 shadow bg-white">
      <div class="flex justify-between items-center mb-2">
        <div>
          <h2 class="font-semibold text-lg"><?= htmlspecialchars($title) ?></h2>
          <p class="text-sm text-gray-500"><?= htmlspecialchars((string)$report['created_at']) ?></p>
        </div>
      </div>

      <?php if (!empty($data['clients_without_checkout_link']) && is_array($data['clients_without_checkout_link'])): ?>
        <p class="text-sm mb-1">
          <strong>❌ Klienci bez linka:</strong>
          <?= implode(', ', array_map('htmlspecialchars', $data['clients_without_checkout_link'])) ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($data['invalid_codes']) && is_array($data['invalid_codes'])): ?>
        <p class="text-sm mb-1">
          <strong>⚠️ Błędne kody:</strong>
          <?php
          $lines = [];
          foreach ($data['invalid_codes'] as $e) {
            $comment = htmlspecialchars((string)($e['comment'] ?? ''));
            $reason  = htmlspecialchars((string)($e['reason'] ?? ''));
            $lines[] = "{$comment}" . ($reason ? " ({$reason})" : "");
          }
          echo implode('; ', $lines);
          ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($data['frequent_products']) && is_array($data['frequent_products'])): ?>
        <p class="text-sm mb-1">
          <strong>🔥 Top produkty:</strong>
          <?php
          $tops = [];
          foreach ($data['frequent_products'] as $p) {
            $name  = htmlspecialchars((string)($p['name'] ?? ''));
            $count = (int)($p['count'] ?? 0);
            $tops[] = "{$name}" . ($count ? " ({$count}x)" : "");
          }
          echo implode(', ', $tops);
          ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($data['active_hours']) && is_array($data['active_hours'])): ?>
        <p class="text-sm mb-1">
          <strong>🕒 Aktywne godziny:</strong>
          <?= implode(', ', array_map('htmlspecialchars', $data['active_hours'])) ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($data['suggestions']) && is_array($data['suggestions'])): ?>
        <div class="text-sm mb-1">
          <strong>💡 Sugestie:</strong>
          <ul class="list-disc ml-6">
            <?php foreach ($data['suggestions'] as $s): ?>
              <li><?= htmlspecialchars((string)$s) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../layout/layout_footer.php'; ?>