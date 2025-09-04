<?php
// admin/settings/szablony/index.php — V4: Statyczne (owner_settings) + Auto-replies (cw_templates w/ fallback)
declare(strict_types=1);

session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/settings/szablony/');
  header("Location: /auth/login.php?redirect={$redirect}");
  exit;
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../layout/layout_header.php';


$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(401);
  echo "<div class='p-6'>Brak uprawnień.</div>";
  require_once __DIR__ . '/../../../layout/layout_footer.php';
  exit;
}

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// —— Konfiguracja ——
// Statyczne (owner_settings)
$static_templates = [
  'template_welcome'          => '👋 Wiadomość powitalna po pierwszym komentarzu',
  'template_tracking'         => '🚚 Wiadomość z linkiem do śledzenia przesyłki',
  'template_reminder'         => '⏰ Przypomnienie o płatności',
  'template_ready_for_pickup' => '📦 Gotowe do odbioru – wiadomość dla klienta',
];

// Auto-replies (CW)
$auto_templates = [
  'success'       => '✅ Poprawny kod produktu',
  'brak_magazyn'  => '📦 Brak produktu na magazynie',
  'zly_kod'       => '❌ Błędny kod produktu',
  'blad_formatu'  => '🌀 Nierozpoznany format wiadomości',
  'pusta'         => '🔇 Pusta wiadomość',
];
$MAX_VARIANTS = 4;
$MAX_LEN      = 300;
$CHANNEL      = 'messenger';

// ——— Helpery CW ———
function qid(string $s): string
{
  return '`' . str_replace('`', '', $s) . '`';
}

/** wykryj kolumny cw_templates; zwraca null jeśli tabela nie istnieje */
function detect_cw_columns(PDO $pdo): ?array
{
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM cw_templates")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cols) return null;
  } catch (Throwable $e) {
    return null;
  }
  $norm = array_map('strtolower', $cols);
  $pick = function (array $cands) use ($norm) {
    foreach ($cands as $c) if (in_array($c, $norm, true)) return $c;
    return null;
  };
  return [
    'table'        => 'cw_templates',
    'id'           => 'id',
    'owner'        => in_array('owner_id', $norm, true) ? 'owner_id' : null,
    'channel'      => $pick(['channel', 'platform']),
    'event'        => $pick(['event_key', 'event', 'type', 'key']),
    'text'         => $pick(['template_text', 'body_text', 'content', 'text', 'message']),
    'template_name' => $pick(['template_name']),
    'variant'      => $pick(['variant']),
    'status'       => $pick(['status', 'state']),
    'active'       => $pick(['active', 'enabled']),
    'language'     => $pick(['language', 'lang']),
    'subject'      => $pick(['subject']),
    'weight'       => $pick(['weight']),
  ];
}

function cw_select_existing(PDO $pdo, array $C, int $owner_id, string $channel, array $eventKeys): array
{
  $out = [];
  if (!$C) return $out;
  $in = implode(',', array_fill(0, count($eventKeys), '?'));
  $orderby = $C['variant'] ? qid($C['variant']) . ' ASC, ' : '';
  $sql = "SELECT " . qid($C['event']) . " ekey, " . qid($C['text']) . " body
          FROM " . qid($C['table']) . "
          WHERE " . qid($C['owner']) . " = ?
            AND " . qid($C['channel']) . " = ?
            AND " . qid($C['event']) . " IN ($in)
          ORDER BY {$orderby}" . qid($C['id']) . " ASC";
  $params = array_merge([$owner_id, $channel], array_keys($eventKeys));
  $st = $pdo->prepare($sql);
  $st->execute($params);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $out[(string)$r['ekey']][] = (string)$r['body'];
  }
  return $out;
}

function cw_save_variants(PDO $pdo, array $C, int $owner_id, string $channel, string $eventKey, array $variants, int $maxLen): void
{
  // DELETE istniejących
  $sqlDel = "DELETE FROM " . qid($C['table']) . "
             WHERE " . qid($C['owner']) . " = :oid
               AND " . qid($C['channel']) . " = :chan
               AND " . qid($C['event']) . "  = :ek";
  $pdo->prepare($sqlDel)->execute(['oid' => $owner_id, 'chan' => $channel, 'ek' => $eventKey]);

  // konstrukcja INSERT
  $cols = [$C['owner'], $C['channel'], $C['event'], $C['text']];
  $vals = [':oid', ':chan', ':ek', ':txt'];

  $has = fn($k) => !empty($C[$k]);
  if ($has('template_name')) {
    $cols[] = $C['template_name'];
    $vals[] = ':tname';
  }
  if ($has('variant')) {
    $cols[] = $C['variant'];
    $vals[] = ':variant';
  }
  if ($has('status')) {
    $cols[] = $C['status'];
    $vals[] = ':status';
  }
  if ($has('active')) {
    $cols[] = $C['active'];
    $vals[] = ':active';
  }
  if ($has('language')) {
    $cols[] = $C['language'];
    $vals[] = ':lang';
  }
  if ($has('weight')) {
    $cols[] = $C['weight'];
    $vals[] = ':weight';
  }
  if ($has('subject')) {
    $cols[] = $C['subject'];
    $vals[] = ':subject';
  }

  $sqlIns = "INSERT INTO " . qid($C['table']) . " (" . implode(',', array_map('qid', $cols)) . ")
             VALUES (" . implode(',', $vals) . ")";
  $ins = $pdo->prepare($sqlIns);

  $i = 1;
  foreach ($variants as $txt) {
    $txt = trim((string)$txt);
    if ($txt === '') continue;
    if (mb_strlen($txt) > $maxLen) $txt = mb_substr($txt, 0, $maxLen);

    $params = [
      'oid'  => $owner_id,
      'chan' => $channel,
      'ek'   => $eventKey,
      'txt'  => $txt,
    ];
    if ($has('template_name')) $params['tname']   = $eventKey . ' v' . $i;
    if ($has('variant'))       $params['variant'] = $i;
    if ($has('status'))        $params['status']  = 'active';
    if ($has('active'))        $params['active']  = 1;
    if ($has('language'))      $params['lang']    = 'pl';
    if ($has('weight'))        $params['weight']  = 1;
    if ($has('subject'))       $params['subject'] = null;

    $ins->execute($params);
    $i++;
  }
}

// ——— POST: zapis ———
$ERRORS = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
      throw new RuntimeException('Błędny token CSRF. Odśwież stronę i spróbuj.');
    }

    // 1) Statyczne → owner_settings
    foreach (array_keys($static_templates) as $key) {
      $val = (string)($_POST[$key] ?? '');
      set_setting($owner_id, $key, $val);
    }

    // 2) Auto-replies → cw_templates (fallback do message_templates, gdy brak tabeli)
    $C = detect_cw_columns($pdo);
    $pdo->beginTransaction();

    if ($C && $C['owner'] && $C['channel'] && $C['event'] && $C['text']) {
      // Wersja CW
      foreach (array_keys($auto_templates) as $ek) {
        $arr = $_POST['auto'][$ek] ?? [];
        if (!is_array($arr)) $arr = [];
        $arr = array_slice($arr, 0, $MAX_VARIANTS);
        cw_save_variants($pdo, $C, $owner_id, $CHANNEL, $ek, $arr, $MAX_LEN);
      }
    } else {
      // Fallback do message_templates (Twoje stare zachowanie)
      foreach (array_keys($auto_templates) as $ek) {
        $pdo->prepare("DELETE FROM message_templates WHERE owner_id=? AND type=? AND platform='facebook'")
          ->execute([$owner_id, $ek]);
        $arr = $_POST['auto'][$ek] ?? [];
        if (!is_array($arr)) $arr = [];
        $arr = array_slice($arr, 0, $MAX_VARIANTS);
        $ins = $pdo->prepare("INSERT INTO message_templates (owner_id,type,platform,content) VALUES (?,?, 'facebook', ?)");
        foreach ($arr as $txt) {
          $txt = trim((string)$txt);
          if ($txt !== '') $ins->execute([$owner_id, $ek, mb_substr($txt, 0, $MAX_LEN)]);
        }
      }
    }

    $pdo->commit();
    $_SESSION['success_message'] = "Zapisano wszystkie szablony.";
    header("Location: /admin/settings/szablony/");
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $ERRORS[] = 'Błąd zapisu: ' . $e->getMessage();
  }
}

// ——— GET: pobranie ———
$values = [];
foreach (array_keys($static_templates) as $key) {
  $values[$key] = (string)get_setting($owner_id, $key);
}

// Auto: preferuj cw_templates; jeśli brak, weź z message_templates
$responses = [];
try {
  $C = detect_cw_columns($pdo);
  if ($C && $C['owner'] && $C['channel'] && $C['event'] && $C['text']) {
    $responses = cw_select_existing($pdo, $C, $owner_id, $CHANNEL, $auto_templates);
  } else {
    foreach (array_keys($auto_templates) as $ek) {
      $st = $pdo->prepare("SELECT content FROM message_templates WHERE owner_id=? AND type=? AND platform='facebook' ORDER BY id ASC");
      $st->execute([$owner_id, $ek]);
      $responses[$ek] = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
  }
} catch (Throwable $e) {
  // zostaw puste — UI pokaże pola pustsze
}
?>
<div class="container mx-auto p-4">
  <div class="flex items-center justify-between mb-4">
    <a href="/admin/settings/" class="text-sm text-blue-600 hover:underline flex items-center gap-1">
      <span class="text-lg">←</span> Wróć do ustawień
    </a>
    <h1 class="text-2xl font-bold">Szablony wiadomości</h1>
  </div>

  <?php if ($ERRORS): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-2 rounded mb-4">
      <ul class="list-disc ml-5"><?php foreach ($ERRORS as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="bg-green-100 text-green-800 p-3 rounded mb-4">
      <?= htmlspecialchars($_SESSION['success_message']);
      unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="grid md:grid-cols-2 gap-6">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Statyczne -->
    <div class="space-y-6">
      <?php foreach ($static_templates as $key => $label): ?>
        <div>
          <label class="block font-semibold mb-1"><?= htmlspecialchars($label) ?></label>
          <textarea name="<?= htmlspecialchars($key) ?>" rows="3" class="w-full border rounded px-3 py-2"><?= htmlspecialchars($values[$key] ?? '') ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Auto-replies (warianty) -->
    <div class="space-y-6">
      <?php foreach ($auto_templates as $eventKey => $label): ?>
        <details class="bg-gray-50 border rounded p-4">
          <summary class="font-semibold cursor-pointer mb-2"><?= htmlspecialchars($label) ?></summary>
          <div class="space-y-2 mt-3">
            <?php
            $vals = $responses[$eventKey] ?? [];
            for ($i = 0; $i < $MAX_VARIANTS; $i++):
              $val = $vals[$i] ?? '';
            ?>
              <input type="text"
                name="auto[<?= htmlspecialchars($eventKey) ?>][]"
                value="<?= htmlspecialchars($val) ?>"
                maxlength="<?= (int)$MAX_LEN ?>"
                class="w-full border rounded px-3 py-2"
                placeholder="Wariant <?= $i + 1 ?>">
            <?php endfor; ?>
            <div class="text-xs text-gray-500">Placeholdery: <code>{{product_name}}</code>, <code>{{product_code}}</code>, <code>{{product_price}}</code>, <code>{{product_qty}}</code>, <code>{{client_name}}</code>, <code>{{order_id}}</code>.</div>
          </div>
        </details>
      <?php endforeach; ?>
    </div>

    <div class="md:col-span-2">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">💾 Zapisz wszystkie szablony</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>