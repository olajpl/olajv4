<?php
// admin/settings/automatyzacje/index.php — V4: auto-replies via cw_templates + parser.prefixes
declare(strict_types=1);

session_start();
if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
  $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/settings/automatyzacje/');
  header("Location: /auth/login.php?redirect={$redirect}");
  exit;
}

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/settings.php';
require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../../layout/layout_header.php';
require_once __DIR__ . '/../../../layout/top_panel.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(401);
  echo "<div class='p-6'>Brak uprawnień.</div>";
  require_once __DIR__ . '/../../../layout/layout_footer.php';
  exit;
}

// === Konfiguracja ===
$MAX_VARIANTS = 4;
$MAX_LEN = 300;
$CHANNELS = ['messenger' => 'Messenger', 'email' => 'E-mail', 'sms' => 'SMS', 'push' => 'Push'];
$channel = strtolower((string)($_GET['channel'] ?? 'messenger'));
if (!isset($CHANNELS[$channel])) $channel = 'messenger';

$EVENTS = [
  'parser.product_ok' => '✅ Poprawny kod produktu',
  'parser.product_out_of_stock' => '📦 Brak produktu',
  'parser.product_not_found' => '❌ Nieznany kod',
];

// === Helpery SQL ===
function detect_cw_template_columns(PDO $pdo): array
{
  $columns = [
    'owner_id' => 'owner_id',
    'id' => 'id',
    'channel' => null,
    'event' => null,
    'text' => null,
    'template_name' => null,
    'variant' => null,
    'weight' => null,
    'active' => null,
    'status' => null,
    'language' => null,
    'subject' => null,
  ];
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM cw_templates")->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) {
    try {
      $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='cw_templates'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e2) {
      $cols = [];
    }
  }
  $norm = array_map('strtolower', $cols);


  foreach (['channel', 'platform'] as $c) if (in_array($c, $norm, true)) {
    $columns['channel'] = $c;
    break;
  }
  foreach (['event_key', 'type', 'event', 'key'] as $c) if (in_array($c, $norm, true)) {
    $columns['event'] = $c;
    break;
  }
  foreach (['template_text', 'body_text', 'content', 'text', 'message'] as $c) if (in_array($c, $norm, true)) {
    $columns['text'] = $c;
    break;
  }
  foreach (['template_name'] as $c) if (in_array($c, $norm, true)) {
    $columns['template_name'] = $c;
    break;
  }
  foreach (['variant'] as $c) if (in_array($c, $norm, true)) {
    $columns['variant'] = $c;
    break;
  }
  foreach (['weight'] as $c) if (in_array($c, $norm, true)) {
    $columns['weight'] = $c;
    break;
  }
  foreach (['active', 'enabled'] as $c) if (in_array($c, $norm, true)) {
    $columns['active'] = $c;
    break;
  }
  foreach (['status', 'state'] as $c) if (in_array($c, $norm, true)) {
    $columns['status'] = $c;
    break;
  }
  foreach (['language', 'lang'] as $c) if (in_array($c, $norm, true)) {
    $columns['language'] = $c;
    break;
  }
  foreach (['subject'] as $c) if (in_array($c, $norm, true)) {
    $columns['subject'] = $c;
    break;
  }


  return $columns;
}
function qcol(string $name): string
{
  return '`' . str_replace('`', '', $name) . '`';
}
function safeStmt(PDO $pdo, string $sql, array $params = []): ?PDOStatement
{
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
  } catch (Throwable $e) {
    return null;
  }
}

$C = detect_cw_template_columns($pdo);
if ($C['channel'] === null || $C['event'] === null || $C['text'] === null) {
  echo "<div class='p-6 text-red-700'>Brakuje wymaganych kolumn w <code>cw_templates</code>.</div>";
  require_once __DIR__ . '/../../../layout/layout_footer.php';
  exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$ERRORS = [];

// === Pobranie istniejących prefixów ===
$parserPrefixesRaw = '';
try {
  $st = $pdo->prepare("SELECT value FROM owner_settings WHERE owner_id = ? AND `key` = 'parser.prefixes' LIMIT 1");
  $st->execute([$owner_id]);
  $parserPrefixesRaw = (string)($st->fetchColumn() ?? '');
} catch (Throwable $__) {
}
$parserPrefixes = implode(', ', json_decode($parserPrefixesRaw, true) ?: []);

// === Zapis ustawień (POST) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf'])) {
      throw new RuntimeException('Błędny token bezpieczeństwa.');
    }

    $channelPost = strtolower((string)($_POST['channel'] ?? 'messenger'));
    if (!isset($CHANNELS[$channelPost])) $channelPost = 'messenger';

    // zapis prefixów
    $prefixesPost = trim((string)($_POST['parser_prefixes'] ?? ''));
    $prefixesArr = array_filter(array_map('trim', explode(',', $prefixesPost)));
    $prefixesJson = json_encode($prefixesArr, JSON_UNESCAPED_UNICODE);
    safeStmt($pdo, "INSERT INTO owner_settings (owner_id, `key`, `value`, created_at, updated_at)
                    VALUES (:oid, 'parser.prefixes', :val, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()", [
      'oid' => $owner_id,
      'val' => $prefixesJson,
    ]);

    // cw_templates
    $raw = $_POST['templates'] ?? [];
    if (!is_array($raw)) $raw = [];

    $pdo->beginTransaction();

    foreach ($EVENTS as $eventKey => $_label) {
      $arr = $raw[$eventKey] ?? [];
      if (!is_array($arr)) $arr = [];
      $arr = array_slice($arr, 0, $MAX_VARIANTS);

      // DELETE
      safeStmt($pdo, "DELETE FROM cw_templates
        WHERE " . qcol('owner_id') . " = :oid AND " . qcol($C['channel']) . " = :chan AND " . qcol($C['event']) . " = :ek", [
        'oid' => $owner_id,
        'chan' => $channelPost,
        'ek' => $eventKey
      ]);

      // INSERT
      $insCols = ['owner_id', $C['channel'], $C['event'], $C['text']];
      $insVals = [':oid', ':chan', ':ek', ':txt'];
      if ($C['template_name']) {
        $insCols[] = $C['template_name'];
        $insVals[] = ':tname';
      }
      if ($C['variant']) {
        $insCols[] = $C['variant'];
        $insVals[] = ':variant';
      }
      if ($C['weight']) {
        $insCols[] = $C['weight'];
        $insVals[] = ':weight';
      }
      if ($C['active']) {
        $insCols[] = $C['active'];
        $insVals[] = ':active';
      }
      if ($C['status']) {
        $insCols[] = $C['status'];
        $insVals[] = ':status';
      }
      if ($C['language']) {
        $insCols[] = $C['language'];
        $insVals[] = ':lang';
      }
      if ($C['subject']) {
        $insCols[] = $C['subject'];
        $insVals[] = ':subject';
      }

      $sqlIns = "INSERT INTO cw_templates (" . implode(',', array_map('qcol', $insCols)) . ") VALUES (" . implode(',', $insVals) . ")";
      $ins = $pdo->prepare($sqlIns);

      $variantNo = 1;
      foreach ($arr as $line) {
        $txt = trim((string)$line);
        if ($txt === '') continue;
        if (mb_strlen($txt) > $MAX_LEN) $txt = mb_substr($txt, 0, $MAX_LEN);

        $params = [
          'oid' => $owner_id,
          'chan' => $channelPost,
          'ek' => $eventKey,
          'txt' => $txt,
          'variant' => $variantNo,
          'weight' => 1,
          'active' => 1,
          'status' => 'active',
          'lang' => 'pl',
          'subject' => '',
          'tname' => $eventKey . ' v' . $variantNo,
        ];
        $ins->execute(array_intersect_key($params, array_flip(array_map(fn($v) => ltrim($v, ':'), $insVals))));
        $variantNo++;
      }
    }

    $pdo->commit();
    header("Location: ?channel={$channelPost}");
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $ERRORS[] = 'Błąd zapisu: ' . $e->getMessage();
  }
}

// === Pobranie istniejących CW Templates
$existing = [];
try {
  $inKeys = implode(',', array_fill(0, count($EVENTS), '?'));
  $orderBy = $C['variant'] ? qcol($C['variant']) . ' ASC, ' : '';
  $sqlSel = "SELECT " . qcol('id') . " AS id, " . qcol($C['event']) . " AS ekey, " . qcol($C['text']) . " AS body
             FROM cw_templates WHERE " . qcol('owner_id') . " = ? AND " . qcol($C['channel']) . " = ? AND " . qcol($C['event']) . " IN ($inKeys)
             ORDER BY {$orderBy} " . qcol('id') . " ASC";
  $params = array_merge([$owner_id, $channel], array_keys($EVENTS));
  $st = safeStmt($pdo, $sqlSel, $params);
  if ($st) while ($r = $st->fetch(PDO::FETCH_ASSOC)) $existing[$r['ekey']][] = $r['body'];
} catch (Throwable $e) {
}

?>

<!-- === HTML === -->
<div class="p-6">
  <h1 class="text-2xl font-bold mb-4">⚙️ Automatyzacje CW + Prefiksy</h1>

  <?php if ($ERRORS): ?>
    <div class="bg-red-100 border border-red-400 text-red-800 p-4 rounded mb-4">
      <?php foreach ($ERRORS as $err): ?><div><?= htmlspecialchars($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" class="space-y-6">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="channel" value="<?= htmlspecialchars($channel) ?>">

    <!-- Prefixy -->
    <details class="bg-white border rounded-xl p-4 shadow-sm">
      <summary class="font-semibold cursor-pointer mb-2">Prefiksy komendy <code>daj</code></summary>
      <div class="space-y-2 mt-3">
        <input type="text"
          name="parser_prefixes"
          value="<?= htmlspecialchars($parserPrefixes) ?>"
          class="w-full border rounded px-3 py-2"
          placeholder="np. daj, moje, biere">
        <div class="text-xs text-gray-500">Wiele wartości rozdziel przecinkiem. Przykład: <code>daj, moje, biere</code></div>
      </div>
    </details>

    <!-- Szablony CW -->
    <?php foreach ($EVENTS as $ekey => $label): ?>
      <details class="bg-white border rounded-xl p-4 shadow-sm">
        <summary class="font-semibold cursor-pointer mb-2"><?= htmlspecialchars($label) ?></summary>
        <div class="space-y-2 mt-3">
          <?php $vals = $existing[$ekey] ?? [];
          for ($i = 0; $i < $MAX_VARIANTS; $i++): ?>
            <input type="text"
              name="templates[<?= htmlspecialchars($ekey) ?>][]"
              value="<?= htmlspecialchars($vals[$i] ?? '') ?>"
              maxlength="<?= (int)$MAX_LEN ?>"
              class="w-full border rounded px-3 py-2"
              placeholder="Wariant <?= $i + 1 ?>">
          <?php endfor; ?>
        </div>
      </details>
    <?php endforeach; ?>

    <div class="pt-2">
      <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">💾 Zapisz ustawienia</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../../../layout/layout_footer.php'; ?>