<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$owner_id = $_SESSION['user']['owner_id'] ?? 0;
if (!$owner_id) die("❌ Brak dostępu");

$prefix = '';
$used_codes = [];

if (isset($_POST['prefix'])) {
  $prefix = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['prefix']);
}
if (isset($_POST['used'])) {
  $used_codes = array_filter(array_map('trim', explode(',', $_POST['used'])));
}

// Pobierz wszystkie istniejące kody z bazy pasujące do prefixu
$stmt = $pdo->prepare("SELECT code FROM products WHERE owner_id = ? AND code LIKE ?");
$stmt->execute([$owner_id, $prefix . '%']);
$db_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$all_used = array_unique(array_merge($db_codes, $used_codes));

// Znajdź największy numer
$max = 0;
foreach ($all_used as $code) {
  if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{3,})$/', $code, $m)) {
    $num = (int)$m[1];
    if ($num > $max) $max = $num;
  }
}

$start = $max + 1;
$codes = [];
$try = 0;
while (count($codes) < 10 && $try < 1000) {
  $code = $prefix . str_pad($start++, 3, '0', STR_PAD_LEFT);
  if (!in_array($code, $all_used)) {
    $codes[] = $code;
  }
  $try++;
}
?>

<div class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50" id="modal-codes">
  <div class="bg-white p-4 rounded shadow-md max-w-md w-full relative">
    <button onclick="document.getElementById('modal-codes').classList.add('hidden')" class="absolute top-2 right-2 text-gray-500 hover:text-black">✖</button>
    <h2 class="text-lg font-semibold mb-2">📋 Sugerowane kody:</h2>
    <div class="flex flex-wrap gap-2" id="suggested-content">
      <?php foreach ($codes as $code): ?>
        <span class="suggested-code cursor-pointer bg-blue-100 px-2 py-1 rounded hover:bg-blue-200">
          <?= htmlspecialchars($code) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function openSuggestModal(inputEl) {
  window.suggestTarget = inputEl;

  let val = inputEl.value.trim();
  let match = val.match(/^([a-zA-Z]+)/);
  let prefix = match ? match[1] : '';

  const modal = document.getElementById('modal-codes');
  const content = document.getElementById('suggested-content');

  modal.classList.remove('hidden');
  content.innerHTML = '⏳ Ładowanie...';

  const used = Array.from(document.querySelectorAll('input[name="code[]"]'))
    .map(el => el.value.trim())
    .filter(val => val !== '')
    .join(',');

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'partials/suggest_codes.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function () {
    if (xhr.status === 200) {
      content.innerHTML = xhr.responseText;
      document.querySelectorAll('.suggested-code').forEach(el => {
        el.addEventListener('click', () => {
          applySuggestedCode(el.textContent.trim());
        });
      });
    } else {
      content.innerHTML = '❌ Błąd ładowania: ' + xhr.status;
    }
  };
  xhr.send('prefix=' + encodeURIComponent(prefix) + '&used=' + encodeURIComponent(used));
}

function applySuggestedCode(code) {
  if (window.suggestTarget) {
    window.suggestTarget.value = code;
    document.getElementById('modal-codes').classList.add('hidden');
  }
}
</script>
