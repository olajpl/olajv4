<?php
// admin/messages/view.php — „Facebook-style” (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($owner_id <= 0) {
  http_response_code(403);
  exit('❌ Brak owner_id w sesji.');
}

$client_id = (int)($_GET['client_id'] ?? 0);
if ($client_id <= 0) {
  http_response_code(400);
  exit('❌ Brak client_id.');
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function h(?string $s): string
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function str_limit(string $txt, int $len = 90, string $suffix = '…'): string
{
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($txt, 'UTF-8') <= $len) return $txt;
    return mb_substr($txt, 0, $len, 'UTF-8') . $suffix;
  }
  return (strlen($txt) <= $len) ? $txt : substr($txt, 0, $len) . $suffix;
}
function starts_with(string $hay, string $needle): bool
{
  return strncmp($hay, $needle, strlen($needle)) === 0;
}

// Klient
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id AND owner_id = :owner_id LIMIT 1");
$stmt->execute([':id' => $client_id, ':owner_id' => $owner_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  http_response_code(404);
  exit('❌ Klient nie znaleziony lub brak dostępu.');
}

// Ostatnie zamówienie
$stmt = $pdo->prepare("
  SELECT id, order_status AS status, created_at
  FROM orders
  WHERE owner_id = :owner_id AND client_id = :client_id
  ORDER BY created_at DESC, id DESC
  LIMIT 1
");
$stmt->execute([':owner_id' => $owner_id, ':client_id' => $client_id]);
$last_order = $stmt->fetch(PDO::FETCH_ASSOC);

// Lewa lista (ostatnia wiadomość per klient)
$stmt = $pdo->prepare("
  SELECT 
    c.id AS client_id,
    c.name AS client_name,
    m.content AS message,
    m.created_at
  FROM clients c
  JOIN messages m 
    ON m.client_id = c.id AND m.owner_id = ?
  WHERE c.owner_id = ?
    AND (m.created_at, m.id) = (
      SELECT m2.created_at, m2.id
      FROM messages m2
      WHERE m2.owner_id = ?
        AND m2.client_id = c.id
      ORDER BY m2.created_at DESC, m2.id DESC
      LIMIT 1
    )
  ORDER BY m.created_at DESC, m.id DESC
  LIMIT 300
");
$stmt->execute([$owner_id, $owner_id, $owner_id]);
$convs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Platforma klienta
$platform_data = null;
try {
  $st = $pdo->prepare("
    SELECT platform, platform_user_id 
    FROM client_platform_ids 
    WHERE client_id = ? AND owner_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$client_id, $owner_id]);
  $platform_data = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  logg('error', 'messages.view.platform_lookup', 'Platform lookup failed', [
    'client_id' => $client_id,
    'owner_id'  => $owner_id,
    'err'       => $e->getMessage(),
  ]);
}

$page_title = "Messenger – " . h($client['name'] ?? 'Klient');
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<style>
  /* === Layout full-height z prawdziwym sticky header/footer i scrollem tylko w środku === */
  :root {
    --border: #e5e7eb;
    --muted: #6b7280;
    --bg: #ffffff;
    --bg-sub: #f8fafc;
    --ink: #111827;
  }

  body {
    background: #f9fafb;
  }

  .messenger-wrap {
    height: calc(100vh - 4rem);
    display: grid;
    grid-template-columns: 320px 1fr 360px;
  }

  .pane {
    display: flex;
    /* KLUCZ: kolumnowy flex */
    flex-direction: column;
    min-height: 0;
    /* pozwala scrolować childa */
    background: var(--bg);
    border-right: 1px solid var(--border);
  }

  .pane:last-child {
    border-right: 0;
    border-left: 1px solid var(--border);
  }

  .pane-header {
    flex: 0 0 auto;
    position: sticky;
    top: 0;
    background: var(--bg);
    z-index: 10;
    border-bottom: 1px solid var(--border);
    padding: .75rem 1rem;
  }

  .pane-scroll {
    height: 100%;
    overflow-y: auto;
  }

  .pane-footer {
    flex: 0 0 auto;
    position: sticky;
    bottom: 0;
    background: var(--bg);
    z-index: 10;
    border-top: 1px solid var(--border);
    padding: .5rem .75rem;
  }

  /* Lewy sidebar */
  .conv-item {
    display: block;
    padding: .75rem 1rem;
    border-bottom: 1px solid #f1f5f9;
  }

  .conv-item:hover {
    background: #f8fafc;
  }

  .conv-item.active {
    background: #eff6ff;
  }

  /* Chat stream */
  .chat-stream {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: .5rem;
  }

  .bubble {
    max-width: 68%;
    padding: .65rem 1rem;
    border-radius: 1.25rem;
    line-height: 1.4;
    white-space: pre-wrap;
    word-wrap: break-word;
    position: relative;
    box-shadow: 0 1px 2px rgba(0, 0, 0, .04), 0 4px 16px rgba(0, 0, 0, .06);
  }

  .bubble-in {
    background: #f1f5f9;
    color: #0f172a;
    border-bottom-left-radius: .4rem;
  }

  .bubble-out {
    background: #1d4ed8;
    color: #fff;
    border-bottom-right-radius: .4rem;
    align-self: flex-end;
  }

  .bubble img {
    border-radius: .5rem;
    display: block;
    max-width: min(420px, 72vw);
    height: auto;
  }

  /* Composer */
  .composer {
    display: flex;
    align-items: center;
    gap: .5rem;
    border: 1px solid var(--border);
    border-radius: 1.1rem;
    padding: .5rem .75rem;
  }

  .composer textarea {
    flex: 1;
    border: 0;
    outline: 0;
    resize: none;
    height: 42px;
    max-height: 180px;
    font-size: .95rem;
  }

  .icon-btn {
    cursor: pointer;
    font-size: 1.15rem;
    line-height: 1;
    opacity: .85;
  }

  .icon-btn:hover {
    opacity: 1;
  }

  .date-sep {
    text-align: center;
    color: var(--muted);
    font-size: .75rem;
    margin: .5rem 0;
  }

  .toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    background: #111827;
    color: #fff;
    padding: .6rem .8rem;
    border-radius: .6rem;
    box-shadow: 0 6px 30px rgba(0, 0, 0, .2);
    font-size: .875rem;
    display: none;
  }

  .tag {
    display: inline-block;
    font-size: .75rem;
    background: #f3f4f6;
    border: 1px solid var(--border);
    color: #374151;
    padding: .1rem .5rem;
    border-radius: 999px;
  }
</style>

<div class="messenger-wrap">
  <!-- Lewy sidebar -->
  <aside class="pane">
    <div class="pane-header">
      <div class="text-lg font-semibold">💬 Konwersacje</div>
    </div>
    <div class="pane-scroll">
      <?php if (!$convs): ?>
        <div class="p-4 text-gray-500">Brak wiadomości od klientów.</div>
        <?php else: foreach ($convs as $conv):
          $txt = (string)($conv['message'] ?? '');
          $isImg = starts_with($txt, '[img]');
        ?>
          <a href="view.php?client_id=<?= (int)$conv['client_id'] ?>"
            class="conv-item <?= ($conv['client_id'] == $client_id) ? 'active' : '' ?>">
            <div class="flex items-center justify-between">
              <div class="font-medium text-gray-800 truncate pr-2">
                <?= h($conv['client_name'] ?? 'Klient') ?>
              </div>
              <div class="text-xs text-gray-400 whitespace-nowrap">
                <?= h(date('H:i d.m', strtotime((string)$conv['created_at']))) ?>
              </div>
            </div>
            <div class="text-sm text-gray-600 truncate">
              <?= $isImg ? '📷 Obrazek' : h(str_limit($txt, 80)) ?>
            </div>
          </a>
      <?php endforeach;
      endif; ?>
    </div>
  </aside>

  <!-- Środek: chat -->
  <main class="pane" style="border-right: 0;">
    <div class="pane-header">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">👤</div>
        <div>
          <div class="font-semibold">🟦 Messenger z <?= h($client['name'] ?? '') ?></div>
          <div class="text-xs text-gray-500">
            ID klienta: <?= (int)$client['id'] ?>
            <?php if ($platform_data): ?> • <span class="tag"><?= h($platform_data['platform']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div id="chat-scroll" class="pane-scroll">
      <div id="chat-stream" class="chat-stream"><!-- AJAX render --></div>
    </div>

    <div class="pane-footer">
      <form id="message-form" action="send_message.php" method="post" enctype="multipart/form-data" class="composer">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
        <input type="hidden" name="owner_id" value="<?= (int)$owner_id ?>">
        <input type="hidden" name="platform" value="<?= h($platform_data['platform'] ?? 'chat') ?>">
        <input type="hidden" name="platform_user_id" value="<?= h($platform_data['platform_user_id'] ?? '') ?>">
        <input type="hidden" name="channel" value="manual">
        <input type="hidden" name="direction" value="out">

        <label class="icon-btn" title="Wyślij obrazek">📎
          <input id="image-input" type="file" name="image" accept="image/*" class="hidden">
        </label>

        <textarea id="message-text" name="message" rows="1" placeholder="Napisz wiadomość..."></textarea>

        <button type="submit" class="icon-btn" title="Wyślij">⏎</button>
      </form>
    </div>
  </main>

  <!-- Prawa kolumna -->
  <aside class="pane">
    <div class="pane-header">
      <div class="text-lg font-semibold">🧾 Szczegóły</div>
    </div>
    <div class="pane-scroll p-4 flex flex-col gap-4">
      <section>
        <h3 class="font-semibold mb-2">Ostatnie zamówienie</h3>
        <?php if ($last_order): ?>
          <div class="text-sm text-gray-700 space-y-1">
            <div><strong>ID:</strong> #<?= (int)$last_order['id'] ?></div>
            <div><strong>Status:</strong> <?= h((string)$last_order['status']) ?></div>
            <div><strong>Data:</strong> <?= h(date('d.m.Y H:i', strtotime((string)$last_order['created_at']))) ?></div>
          </div>
          <a href="/admin/orders/view.php?id=<?= (int)$last_order['id'] ?>"
            class="mt-2 block text-center bg-blue-600 text-white px-3 py-2 rounded hover:bg-blue-700 transition text-sm">
            📦 Zobacz zamówienie
          </a>
        <?php else: ?>
          <div class="text-sm text-gray-600">Brak zamówień.</div>
        <?php endif; ?>
      </section>

      <section>
        <form action="/admin/orders/create_from_chat.php" method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="client_id" value="<?= (int)$client_id ?>">
          <button type="submit" class="w-full bg-green-600 text-white px-3 py-2 rounded hover:bg-green-700 transition text-sm">
            ➕ Utwórz nowe zamówienie
          </button>
        </form>
      </section>
    </div>
  </aside>
</div>

<div id="toast" class="toast"></div>
<audio id="notif-sound" src="/sounds/notify.mp3" preload="auto"></audio>

<script>
  // JS: render chat + auto-scroll + skróty
  const chatStream = document.getElementById('chat-stream');
  const chatScroll = document.getElementById('chat-scroll');
  const notifSound = document.getElementById('notif-sound');
  const clientId = <?= (int)$client_id ?>;
  const imageInput = document.getElementById('image-input');
  const form = document.getElementById('message-form');
  const ta = document.getElementById('message-text');
  const toast = document.getElementById('toast');

  let lastCount = 0;

  function showToast(msg) {
    toast.textContent = msg;
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2400);
  }

  function formatDateSep(ts) {
    try {
      const d = new Date((ts || '').replace(' ', 'T'));
      return d.toLocaleDateString('pl-PL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
      });
    } catch {
      return ts;
    }
  }

  function renderMessages(list) {
    chatStream.innerHTML = '';
    let lastDate = '';
    list.forEach(msg => {
      const created = String(msg.created_at || '');
      const day = created.substring(0, 10);
      if (day !== lastDate) {
        const sep = document.createElement('div');
        sep.className = 'date-sep';
        sep.textContent = formatDateSep(created);
        chatStream.appendChild(sep);
        lastDate = day;
      }

      const wrap = document.createElement('div');
      wrap.style.display = 'flex';
      wrap.style.justifyContent = (msg.direction === 'out') ? 'flex-end' : 'flex-start';

      const bubble = document.createElement('div');
      bubble.className = 'bubble ' + ((msg.direction === 'out') ? 'bubble-out' : 'bubble-in');

      const text = String(msg.message || '');
      if (text.startsWith('[img]')) {
        const img = document.createElement('img');
        img.src = text.replace('[img]', '').trim();
        img.alt = 'img';
        img.loading = 'lazy';
        bubble.appendChild(img);
      } else {
        bubble.textContent = text || ' ';
      }

      wrap.appendChild(bubble);
      chatStream.appendChild(wrap);
    });
  }

  async function fetchMessages() {
    try {
      const res = await fetch(`api/fetch_messages.php?client_id=${clientId}&as=json`, {
        cache: 'no-store'
      });
      if (!res.ok) {
        console.error('fetch_messages HTTP', res.status);
        return;
      }
      const data = await res.json();
      if (!Array.isArray(data)) {
        console.error('fetch_messages payload', data);
        return;
      }

      const nearBottom = chatScroll.scrollTop + chatScroll.clientHeight >= chatScroll.scrollHeight - 80;
      const oldCount = lastCount;

      if (data.length !== lastCount) {
        renderMessages(data);
        lastCount = data.length;

        if (nearBottom || data.length > oldCount) chatScroll.scrollTop = chatScroll.scrollHeight;
        if (data.length > oldCount) {
          try {
            notifSound && notifSound.play().catch(() => {});
          } catch {}
        }
      }
    } catch (e) {
      console.error('fetch_messages error', e);
    }
  }
  let lastCount = 0;

  function renderMessages(list) {
    /* zostaw tak jak masz */ }

  async function fetchInitial() {
    const res = await fetch(`api/fetch_messages.php?client_id=${clientId}&as=json`, {
      cache: 'no-store'
    });
    const data = await res.json();
    if (Array.isArray(data)) {
      renderMessages(data);
      lastCount = data.length;
      chatScroll.scrollTop = chatScroll.scrollHeight;
    }
  }

  // SSE
  function startStream() {
    const since = encodeURIComponent((new Date()).toISOString().slice(0, 19).replace('T', ' '));
    const es = new EventSource(`api/stream.php?client_id=${clientId}&since=${since}`);

    es.addEventListener('message', (ev) => {
      try {
        const msg = JSON.parse(ev.data);
        // dołóż jeden do listy zamiast przeładowywać wszystko:
        const data = [{
          ...msg
        }]; // pojedynczy
        const nearBottom = chatScroll.scrollTop + chatScroll.clientHeight >= chatScroll.scrollHeight - 80;
        // quick-append:
        const wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.justifyContent = (msg.direction === 'out') ? 'flex-end' : 'flex-start';
        const bubble = document.createElement('div');
        bubble.className = 'bubble ' + ((msg.direction === 'out') ? 'bubble-out' : 'bubble-in');
        const text = String(msg.message || '');
        if (text.startsWith('[img]')) {
          const img = document.createElement('img');
          img.src = text.replace('[img]', '').trim();
          img.loading = 'lazy';
          bubble.appendChild(img);
        } else bubble.textContent = text || ' ';
        wrap.appendChild(bubble);
        chatStream.appendChild(wrap);
        lastCount++;

        if (nearBottom) chatScroll.scrollTop = chatScroll.scrollHeight;
      } catch (_) {}
    });

    es.addEventListener('ping', () => {
      /* no-op */ });
    es.onerror = () => {
      es.close();
      setTimeout(startStream, 3000);
    }; // auto-reconnect
  }

  fetchInitial().then(startStream);

  // Init + polling
  fetchMessages().then(() => {
    chatScroll.scrollTop = chatScroll.scrollHeight;
  });
  setInterval(fetchMessages, 4000);

  // Skróty wysyłania
  ta.addEventListener('keydown', e => {
    if ((e.key === 'Enter' && !e.shiftKey) || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) {
      e.preventDefault();
      form.submit();
    }
  });

  // Auto-submit po wyborze pliku
  imageInput.addEventListener('change', () => {
    if (imageInput.files && imageInput.files.length) form.submit();
  });

  // Toast po powrocie
  const usp = new URLSearchParams(location.search);
  if (usp.get('msg') === 'sent') showToast('✅ Wiadomość wysłana');
  if (usp.get('msg') === 'error') showToast('❌ Błąd wysyłki');
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>