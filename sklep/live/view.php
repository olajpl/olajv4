<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/live_embed.php';

$live_id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT id, owner_id, title, platform, stream_url, status FROM live_streams WHERE id=? LIMIT 1");
$st->execute([$live_id]);
$stream = $st->fetch(PDO::FETCH_ASSOC);
if (!$stream) {
    http_response_code(404);
    exit('Nie znaleziono transmisji');
}

?>
<!doctype html>
<html lang="pl">

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($stream['title'] ?? 'Transmisja') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .player-wrap {
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(0, 0, 0, .08);
        }

        .ratio-16x9 {
            aspect-ratio: 16/9;
            background: #000;
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800">
    <div class="max-w-5xl mx-auto p-6 space-y-4">
        <h1 class="text-2xl font-bold"><?= htmlspecialchars($stream['title'] ?? 'Transmisja') ?></h1>

        <div class="player-wrap">
            <div class="ratio-16x9">
                <?= liveEmbedHtml((string)$stream['platform'], (string)($stream['stream_url'] ?? '')) ?: '<div class="w-full h-full grid place-items-center text-white/80">Brak poprawnego embeda</div>' ?>
            </div>
        </div>

        <!-- 💬 Live Chat Dock -->
        <div id="live-chat-dock" style="
  position:fixed;left:0;right:0;bottom:0;z-index:1000;
  background:#fff;border-top:1px solid #e5e7eb;
  box-shadow:0 -8px 24px rgba(0,0,0,.08);
  font-family:inherit;">
            <div style="max-width:1100px;margin:0 auto;padding:8px 12px;display:flex;gap:12px;align-items:flex-end;">
                <!-- lista wiadomości -->
                <div id="chat-list" style="
      flex:1 1 auto;max-height:240px;overflow:auto;
      display:flex;flex-direction:column;gap:8px;padding:6px 2px;">
                    <!-- wiadomości pojawiają się tutaj -->
                </div>
                <!-- input -->
                <form id="chat-form" style="flex:0 0 380px;display:flex;gap:8px;align-items:center;">
                    <input id="chat-input" type="text" placeholder="<?= !empty($_SESSION['client_id']) ? 'Napisz wiadomość…' : 'Zaloguj się, aby pisać' ?>"
                        <?= empty($_SESSION['client_id']) ? 'disabled' : '' ?>
                        style="flex:1;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;font-size:14px;">
                    <button id="chat-send" type="submit" <?= empty($_SESSION['client_id']) ? 'disabled' : '' ?>
                        style="background:#ec4899;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:600;">
                        Wyślij
                    </button>
                </form>
            </div>
        </div>

        <!-- (opcjonalnie) opis, plan live, lista produktów itd. -->

    </div>

    <!-- ⚡ Overlay z ofertą LIVE (ten sam co na indexie; podmień liveId) -->
    <div id="live-overlay" style="position:fixed;bottom:20px;right:20px;z-index:1000;background:white;border-radius:12px;padding:12px;box-shadow:0 4px 10px rgba(0,0,0,0.15);max-width:250px;display:none;">
        <img id="live-offer-img" src="" alt="" style="width:100%;border-radius:8px;">
        <div id="live-offer-name" style="font-weight:bold;margin-top:8px;"></div>
        <div id="live-offer-price" style="color:#e60023;font-size:18px;margin-top:4px;"></div>
        <button id="live-offer-add" style="margin-top:8px;width:100%;background:#28a745;color:white;border:none;padding:8px;border-radius:6px;cursor:pointer;">➕ Dodaj</button>
    </div>
    <script>
        (() => {
            const liveId = <?= (int)$live_id ?>;
            const overlay = document.getElementById('live-overlay');
            const nameEl = document.getElementById('live-offer-name');
            const priceEl = document.getElementById('live-offer-price');
            const imgEl = document.getElementById('live-offer-img');
            const btn = document.getElementById('live-offer-add');

            async function refreshOffer() {
                try {
                    const res = await fetch('/api/live/get_active_offer.php?live_id=' + liveId, {
                        credentials: 'include'
                    });
                    const data = await res.json();
                    if (data?.success && data?.offer) {
                        overlay.style.display = 'block';
                        nameEl.textContent = data.offer.name;
                        priceEl.textContent = data.offer.price_formatted;
                        imgEl.src = data.offer.image_url || '/img/no-image.png';
                        btn.onclick = async () => {
                            const r = await fetch('/api/cart/add.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    product_id: data.offer.id,
                                    quantity: 1,
                                    context: 'live',
                                    live_id: liveId
                                })
                            });
                            const j = await r.json().catch(() => ({}));
                            alert(j?.success ? 'Dodano do koszyka!' : (j?.message || 'Błąd dodawania'));
                        };
                    } else {
                        overlay.style.display = 'none';
                    }
                } catch (e) {}
            }
            setInterval(refreshOffer, 5000);
            refreshOffer();
        })();
    </script>
    <script>
        (() => {
            const liveId = <?= (int)$live_id ?>;
            const list = document.getElementById('chat-list');
            const form = document.getElementById('chat-form');
            const input = document.getElementById('chat-input');
            const name = document.getElementById('chat-name');
            const count = document.getElementById('chat-count');

            let lastId = 0;
            let usingSSE = false;
            let pollTimer = null;

            function el(tag, cls, txt) {
                const e = document.createElement(tag);
                if (cls) e.className = cls;
                if (txt !== undefined) e.textContent = txt;
                return e;
            }

            function render(items) {
                if (!Array.isArray(items) || !items.length) return;
                const atBottom = (list.scrollTop + list.clientHeight + 10) >= list.scrollHeight;

                for (const it of items) {
                    const row = el('div', 'flex gap-2 items-start');
                    const avatar = el('div', 'w-8 h-8 rounded-full bg-pink-100 grid place-items-center text-pink-700 text-xs');
                    avatar.textContent = (it.display_name || 'U').slice(0, 1).toUpperCase();
                    const body = el('div', 'flex-1');
                    const head = el('div', 'text-xs text-gray-500');
                    head.textContent = (it.display_name || 'Użytkownik') + ' • ' + new Date(it.created_at.replace(' ', 'T')).toLocaleTimeString();
                    const msg = el('div', 'text-sm');
                    msg.textContent = it.message; // z bazy plaintext – bezpiecznie
                    body.appendChild(head);
                    body.appendChild(msg);
                    row.appendChild(avatar);
                    row.appendChild(body);
                    list.appendChild(row);
                    lastId = Math.max(lastId, parseInt(it.id, 10) || 0);
                }
                count.textContent = 'ostatnie: ' + lastId;

                if (atBottom) list.scrollTop = list.scrollHeight;
            }

            async function fetchNew() {
                try {
                    const r = await fetch(`/api/live/chat/list.php?live_id=${liveId}&after_id=${lastId}&limit=50`, {
                        cache: 'no-store'
                    });
                    const j = await r.json();
                    if (j?.success) render(j.items || []);
                } catch (e) {}
            }

            // SSE (jeśli się uda)
            try {
                const es = new EventSource(`/api/live/chat/sse.php?live_id=${liveId}&last_id=0`);
                es.addEventListener('messages', (e) => {
                    usingSSE = true;
                    const data = JSON.parse(e.data);
                    render(data.items || []);
                });
                es.onerror = () => {
                    /* fallback do pollingu niżej */
                };
                // dociągnij pierwszą partię
                fetchNew();
            } catch (e) {
                // nic – polecimy pollingiem
            }

            // Polling fallback (i tak przyda się od czasu do czasu)
            function schedulePoll() {
                clearInterval(pollTimer);
                pollTimer = setInterval(fetchNew, usingSSE ? 8000 : 2500);
            }
            schedulePoll();
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    clearInterval(pollTimer);
                } else {
                    fetchNew();
                    schedulePoll();
                }
            });

            // Wysyłka
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const txt = input.value.trim();
                if (!txt) return;
                const payload = {
                    live_id: liveId,
                    message: txt,
                    display_name: name.value.trim() || undefined
                };
                try {
                    const r = await fetch('/api/live/chat/post.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });
                    const j = await r.json();
                    if (j?.success) {
                        input.value = '';
                        fetchNew(); // szybki refresh
                    } else {
                        alert(j?.error || 'Nie udało się wysłać');
                    }
                } catch (e) {
                    alert('Błąd sieci');
                }
            });

        })();
    </script>
    <script>
        (() => {
            const liveId = <?= (int)$live_id ?>;
            const list = document.getElementById('chat-list');
            const form = document.getElementById('chat-form');
            const input = document.getElementById('chat-input');
            const btn = document.getElementById('chat-send');

            let lastId = 0;
            let timer = null;

            function msgRow(it) {
                const wrap = document.createElement('div');
                wrap.style.display = 'flex';
                wrap.style.gap = '8px';
                wrap.style.alignItems = 'flex-start';

                const av = document.createElement('div');
                av.textContent = (it.display_name || 'K').slice(0, 1).toUpperCase();
                av.style.cssText = 'width:28px;height:28px;border-radius:999px;background:#fce7f3;color:#be185d;display:grid;place-items:center;font-size:12px;font-weight:700;';
                const body = document.createElement('div');
                const head = document.createElement('div');
                head.textContent = (it.display_name || 'Klient') + ' • ' + new Date(it.created_at.replace(' ', 'T')).toLocaleTimeString();
                head.style.cssText = 'font-size:11px;color:#6b7280;margin-bottom:2px;';
                const msg = document.createElement('div');
                msg.textContent = it.message;
                msg.style.cssText = 'font-size:14px;line-height:1.35;';
                body.appendChild(head);
                body.appendChild(msg);
                wrap.appendChild(av);
                wrap.appendChild(body);
                return wrap;
            }

            function render(items) {
                if (!items?.length) return;
                const atBottom = (list.scrollTop + list.clientHeight + 10) >= list.scrollHeight;
                for (const it of items) {
                    list.appendChild(msgRow(it));
                    lastId = Math.max(lastId, parseInt(it.id, 10) || 0);
                }
                if (atBottom) list.scrollTop = list.scrollHeight;
            }

            async function pull() {
                try {
                    const r = await fetch(`/api/live/chat/list.php?live_id=${liveId}&after_id=${lastId}&limit=50`, {
                        cache: 'no-store'
                    });
                    const j = await r.json();
                    if (j?.success) render(j.items || []);
                } catch (e) {}
            }

            function loop() {
                clearInterval(timer);
                timer = setInterval(pull, document.hidden ? 7000 : 2500);
            }

            // start
            pull();
            loop();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    pull();
                }
                loop();
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const txt = (input.value || '').trim();
                if (!txt) return;
                btn.disabled = true;
                try {
                    const r = await fetch('/api/live/chat/post.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            live_id: liveId,
                            message: txt
                        })
                    });
                    const j = await r.json();
                    if (j?.success) {
                        input.value = '';
                        pull();
                    } else {
                        alert(j?.error || 'Nie udało się wysłać');
                    }
                } catch (e) {
                    alert('Błąd sieci');
                } finally {
                    btn.disabled = false;
                }
            });
        })();
    </script>

</body>

</html>