<?php
// admin/ai/chat.php — widok AI chatu dla admina (Olaj V4)
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
if ($ownerId <= 0 || $userId <= 0) {
    http_response_code(403);
    echo "Brak dostępu (owner_id / user_id).";
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>AI Chat — Olaj V4</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="/assets/tailwind.css" rel="stylesheet">
<style>
  .bubble{max-width:72ch; border-radius:1rem; padding:.75rem 1rem; margin:.25rem 0;}
  .bubble-user{background:#1f2937; color:#fff; margin-left:auto;}
  .bubble-assistant{background:#f3f4f6; color:#111827; margin-right:auto;}
  .msg-time{font-size:.75rem; opacity:.6; margin-top:.15rem}
  .scrollbox{height:60vh; overflow:auto;}
</style>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/../../layout/layout_header.php'; ?>

<div class="max-w-4xl mx-auto p-4">
  <div class="flex items-center justify-between mb-3">
    <h1 class="text-2xl font-semibold">AI chat dla admina</h1>
    <div class="text-sm opacity-70">owner: #<?= (int)$ownerId ?> · user: #<?= (int)$userId ?></div>
  </div>

  <div id="chatBox" class="scrollbox bg-white shadow rounded-2xl p-4"></div>

  <form id="chatForm" class="mt-3 bg-white shadow rounded-2xl p-2 flex gap-2">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input id="msg" name="message" class="flex-1 px-3 py-2 rounded-xl border border-gray-200"
           placeholder="Napisz do asystenta… (Shift+Enter = nowa linia)" autocomplete="off">
    <button class="px-4 py-2 rounded-xl bg-blue-600 text-white hover:bg-blue-700">Wyślij</button>
  </form>

  <div class="mt-3 grid gap-2 grid-cols-2 md:grid-cols-4">
    <button data-snippet="Stwórz krótki opis produktu w 4 zdaniach: "
            class="px-3 py-2 rounded-xl bg-gray-800 text-white">Opis produktu</button>
    <button data-snippet="Wygeneruj punkty SEO (title, description, keywords) dla: "
            class="px-3 py-2 rounded-xl bg-gray-800 text-white">SEO</button>
    <button data-snippet="Zaproponuj komunikat CW dla zdarzenia 'cart.item_added' w stylu Olaj V4: "
            class="px-3 py-2 rounded-xl bg-gray-800 text-white">Szablon CW</button>
    <button id="clearBtn" class="px-3 py-2 rounded-xl bg-red-600 text-white">Wyczyść historię</button>
  </div>
</div>

<script>
const chatBox  = document.getElementById('chatBox');
const chatForm = document.getElementById('chatForm');
const msgInput = document.getElementById('msg');

function esc(s){return s.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));}

function bubble(role, content, time){
  const el = document.createElement('div');
  el.className = 'bubble ' + (role === 'user' ? 'bubble-user' : 'bubble-assistant');
  el.innerHTML = '<div>'+esc(content)+'</div>' + (time?('<div class="msg-time">'+esc(time)+'</div>'):'');
  chatBox.appendChild(el);
  chatBox.scrollTop = chatBox.scrollHeight;
}

async function loadHistory(){
  const r = await fetch('ajax_chat.php?action=history', {credentials:'same-origin'});
  const j = await r.json();
  chatBox.innerHTML = '';
  if (j.ok && Array.isArray(j.items)) {
    j.items.forEach(it => bubble(it.role, it.message, it.created_at));
  } else {
    bubble('assistant','Nie udało się pobrać historii.','');
  }
}
loadHistory();

chatForm.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = msgInput.value.trim();
  if(!text) return;

  bubble('user', text, new Date().toLocaleTimeString());
  msgInput.value='';

  const fd = new FormData(chatForm);
  fd.set('message', text);
  const r = await fetch('ajax_chat.php', { method:'POST', body:fd, credentials:'same-origin' });
  const j = await r.json();
  if(j.ok && j.reply){
    bubble('assistant', j.reply.content, new Date().toLocaleTimeString());
  }else{
    bubble('assistant', 'Błąd: ' + (j.error || 'nieznany'), '');
  }
});

document.querySelectorAll('button[data-snippet]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    msgInput.value = btn.dataset.snippet;
    msgInput.focus();
  });
});

document.getElementById('clearBtn').addEventListener('click', async ()=>{
  if(!confirm('Wyczyścić historię chatu dla bieżącego użytkownika?')) return;
  const r = await fetch('ajax_chat.php?action=clear', {credentials:'same-origin'});
  const j = await r.json();
  if(j.ok){ loadHistory(); } else { alert('Nie udało się wyczyścić: ' + (j.error||'')); }
});
</script>

<?php include __DIR__ . '/../../layout/layout_footer.php'; ?>
</body>
</html>
