<?php
// admin/raffles/view.php — podgląd losowania + akcje + animacja 3-bębnowa + picker klienta
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Brak ID";
  exit;
}

// Załaduj losowanie
$st = $pdo->prepare("
  SELECT d.*, COALESCE(ls.title,'(offline)') AS live_title
  FROM draws d
  LEFT JOIN live_streams ls ON ls.id = d.live_stream_id
  WHERE d.id = :id AND d.owner_id = :oid
  LIMIT 1
");
$st->execute([':id' => $id, ':oid' => $ownerId]);
$draw = $st->fetch(PDO::FETCH_ASSOC);
if (!$draw) {
  http_response_code(404);
  echo "Nie znaleziono losowania";
  exit;
}

// Uczestnicy (na start)
$en = $pdo->prepare("SELECT id, platform, platform_id, display_name, weight, created_at FROM draw_entries WHERE draw_id=:id ORDER BY id DESC");
$en->execute([':id' => $id]);
$entries = $en->fetchAll(PDO::FETCH_ASSOC);

// Wynik (jeśli istnieje)
$res = $pdo->prepare("SELECT id, platform, platform_id, display_name, winner_index, won_at FROM draw_results WHERE draw_id=:id ORDER BY id DESC LIMIT 1");
$res->execute([':id' => $id]);
$result = $res->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="max-w-5xl mx-auto p-4">
  <div class="flex items-center justify-between gap-4 mb-4">
    <div>
      <h1 class="text-2xl font-bold">🎲 Losowanie #<?= (int)$draw['id'] ?></h1>
      <div class="text-sm text-gray-600">
        Tytuł: <span class="font-medium"><?= h((string)$draw['title']) ?></span> •
        LIVE: <span><?= h((string)$draw['live_title']) ?></span> •
        Status: <span class="font-semibold"><?= h((string)$draw['status']) ?></span> •
        Uczestników: <span class="font-semibold" id="participantsCount"><?= (int)$draw['participants_count'] ?></span>
      </div>
    </div>
    <div>
      <a href="index.php" class="text-blue-600 hover:underline">← Wróć</a>
    </div>
  </div>

  <!-- Akcje -->
  <div class="flex flex-wrap items-center gap-2 mb-6">
    <?php if ($draw['status'] === 'arming'): ?>
      <button id="btn-freeze" class="px-3 py-2 rounded bg-amber-600 text-white hover:bg-amber-700">🧊 Freeze (zamroź)</button>
    <?php endif; ?>
    <?php if ($draw['status'] === 'frozen'): ?>
      <button id="btn-draw" class="px-3 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700">🎉 Losuj zwycięzcę</button>
    <?php endif; ?>
    <button id="btn-reset" class="px-3 py-2 rounded border hover:bg-gray-50">♻ Resetuj listę</button>
  </div>

  <!-- Formularz dodania uczestnika (w statusie arming) -->
  <?php if ($draw['status'] === 'arming'): ?>
    <div class="mb-6 border rounded p-4">
      <h2 class="font-semibold mb-3">➕ Dodaj uczestnika</h2>
      <form id="entryForm" class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <input type="hidden" name="draw_id" value="<?= (int)$draw['id'] ?>">

        <div>
          <label class="text-sm block mb-1">Platforma</label>
          <select name="platform" id="platformSelect" class="w-full border rounded px-2 py-2">
            <option value="manual">manual</option>
            <option value="client">client</option>
            <option value="messenger">messenger</option>
          </select>
        </div>

        <!-- Picker klienta (tylko dla platform=client) -->
        <div id="clientPicker" class="md:col-span-3" style="display:none;">
          <label class="text-sm block mb-1">Szukaj klienta (imię, email, telefon, token)</label>
          <div class="relative">
            <input id="clientSearch" class="w-full border rounded px-2 py-2" placeholder="np. Jan, 600..., mail@...">
            <div id="clientResults" class="absolute z-10 left-0 right-0 bg-white border rounded shadow-sm max-h-64 overflow-auto" style="display:none;"></div>
          </div>
          <!-- UWAGA: to pole NIE ma name, żeby nie dublować platform_id -->
          <input type="hidden" id="clientId">
        </div>

        <div>
          <label class="text-sm block mb-1">Platform ID</label>
          <input name="platform_id" id="platformIdInput" class="w-full border rounded px-2 py-2" placeholder="np. client_id lub PSID">
        </div>

        <div>
          <label class="text-sm block mb-1">Display name</label>
          <input name="display_name" id="displayNameInput" class="w-full border rounded px-2 py-2" placeholder="jeśli puste + platform=client → pobierz z clients">
        </div>

        <div>
          <label class="text-sm block mb-1">Weight</label>
          <input type="number" name="weight" min="1" value="1" class="w-full border rounded px-2 py-2">
        </div>

        <div class="md:col-span-4">
          <button class="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">Dodaj</button>
        </div>
      </form>
      <div id="entryMsg" class="text-sm mt-2"></div>
    </div>
  <?php endif; ?>

  <!-- Wynik -->
  <?php if ($result): ?>
    <div class="mb-6 p-4 rounded border bg-emerald-50">
      <div class="font-semibold mb-1">🎉 Zwycięzca</div>
      <div><?= h($result['display_name']) ?> <span class="text-gray-600">(<?= h($result['platform']) ?> <?= h((string)$result['platform_id']) ?>)</span></div>
      <div class="text-xs text-gray-600">Index: <?= (int)$result['winner_index'] ?> • <?= h((string)$result['won_at']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Lista uczestników -->
  <div class="border rounded overflow-x-auto">
    <table class="min-w-full text-sm" id="entriesTable">
      <thead class="bg-gray-50">
        <tr class="text-left">
          <th class="px-3 py-2">ID</th>
          <th class="px-3 py-2">Display</th>
          <th class="px-3 py-2">Platform</th>
          <th class="px-3 py-2">PID</th>
          <th class="px-3 py-2">Weight</th>
          <th class="px-3 py-2">Dodano</th>
          <th class="px-3 py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($entries): foreach ($entries as $e): ?>
            <tr class="border-t">
              <td class="px-3 py-2"><?= (int)$e['id'] ?></td>
              <td class="px-3 py-2"><?= h($e['display_name']) ?></td>
              <td class="px-3 py-2"><?= h($e['platform']) ?></td>
              <td class="px-3 py-2"><?= h((string)$e['platform_id']) ?></td>
              <td class="px-3 py-2"><?= (int)$e['weight'] ?></td>
              <td class="px-3 py-2"><?= h((string)$e['created_at']) ?></td>
              <td class="px-3 py-2">
                <?php if ($draw['status'] === 'arming'): ?>
                  <button class="text-red-600 hover:underline" onclick="deleteEntry(<?= (int)$e['id'] ?>)">Usuń</button>
                  <?php if (!empty($e['platform_id'])): ?>
                    <button class="text-amber-700 hover:underline ml-2" onclick="banEntry('<?= h($e['platform']) ?>','<?= h((string)$e['platform_id']) ?>')">Banuj</button>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach;
        else: ?>
          <tr>
            <td colspan="7" class="px-3 py-6 text-center text-gray-500">Brak uczestników</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal animacji: trzy kolumny (ukryty display:none) -->
<div id="drawModal" style="display:none;" class="fixed inset-0 z-[100]">
  <div class="absolute inset-0 bg-black/50"></div>
  <div class="absolute inset-0 flex items-center justify-center p-4">
    <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl overflow-hidden">
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <h3 class="font-semibold">🎉 Losowanie w toku…</h3>
        <button id="drawCloseBtn" class="text-gray-500 hover:text-gray-800" title="Zamknij">&times;</button>
      </div>
      <div class="p-4">
        <div class="relative h-60 overflow-hidden border rounded-lg bg-gray-50">
          <!-- wskaźnik (środek) -->
          <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 pointer-events-none z-10">
            <div class="mx-auto w-0 h-0 border-l-[10px] border-r-[10px] border-t-[12px] border-l-transparent border-r-transparent border-t-pink-500"></div>
          </div>
          <!-- 3 kolumny -->
          <div class="absolute inset-0 grid grid-cols-3 gap-0">
            <div class="relative border-r">
              <div class="absolute inset-0 reel" id="reel1"></div>
            </div>
            <div class="relative border-r">
              <div class="absolute inset-0 reel" id="reel2"></div>
            </div>
            <div class="relative">
              <div class="absolute inset-0 reel" id="reel3"></div>
            </div>
          </div>
        </div>
        <div id="drawStatus" class="text-sm text-gray-600 mt-3">Przygotowuję bębny…</div>
      </div>
    </div>
  </div>
</div>

<style>
  .reel .cell {
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-bottom: 1px dashed #e5e7eb;
    font-size: 15px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 12px;
    background: #fff;
  }

  .reel .cell:nth-child(2n) {
    background: #fafafa;
  }

  /* Wyniki klienta */
  #clientResults .item {
    padding: 8px 10px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  #clientResults .item:hover {
    background: #f3f4f6;
  }

  #clientResults .title {
    font-weight: 600;
    font-size: 14px;
  }

  #clientResults .meta {
    font-size: 12px;
    color: #6b7280;
  }
</style>

<script>
  (() => {
    const drawId = <?= (int)$draw['id'] ?>;

    // Helpers
    async function postJSON(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: new URLSearchParams(payload)
      });
      return res.json();
    }

    function refreshAfter() {
      location.reload();
    }

    // Buttons
    const btnFreeze = document.getElementById('btn-freeze');
    if (btnFreeze) btnFreeze.addEventListener('click', async () => {
      const r = await postJSON('api/freeze.php', {
        id: drawId
      });
      if (r && r.success) refreshAfter();
      else alert((r && r.error) || 'Błąd freeze');
    });

    const btnReset = document.getElementById('btn-reset');
    if (btnReset) btnReset.addEventListener('click', async () => {
      if (!confirm('Na pewno zresetować listę?')) return;
      const r = await postJSON('api/reset.php', {
        id: drawId
      });
      if (r && r.success) refreshAfter();
      else alert((r && r.error) || 'Błąd reset');
    });

    // Add entry
    const entryForm = document.getElementById('entryForm');
    if (entryForm) entryForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(entryForm);
      const payload = Object.fromEntries(fd.entries());
      const r = await postJSON('api/add_entry.php', payload);
      const box = document.getElementById('entryMsg');
      if (r && (r.success || r.ok)) {
        box.textContent = r.duplicate ? 'Duplikat — wpis pominięty.' : 'Dodano uczestnika.';
        entryForm.reset();
        refreshAfter();
      } else {
        box.textContent = (r && r.error) ? ('Błąd: ' + r.error) : 'Błąd dodawania.';
      }
    });

    // Row actions
    window.deleteEntry = async (entryId) => {
      if (!confirm('Usunąć wpis #' + entryId + '?')) return;
      const r = await postJSON('api/delete_entry.php', {
        id: entryId,
        draw_id: drawId
      });
      if (r && r.success) refreshAfter();
      else alert((r && r.error) || 'Błąd usuwania');
    };
    window.banEntry = async (platform, platform_id) => {
      if (!confirm('Zbanować uczestnika ' + platform + ':' + platform_id + ' i usunąć jego wpisy?')) return;
      const r = await postJSON('api/ban_entry.php', {
        draw_id: drawId,
        platform,
        platform_id
      });
      if (r && r.success) refreshAfter();
      else alert((r && r.error) || 'Błąd ban');
    };

    // ==== CLIENT PICKER ====
    const platformSelect = document.getElementById('platformSelect');
    const clientPickerWrap = document.getElementById('clientPicker');
    const clientSearch = document.getElementById('clientSearch');
    const clientResults = document.getElementById('clientResults');
    const clientIdHidden = document.getElementById('clientId'); // tylko pomocniczo (bez name)
    const platformIdInput = document.getElementById('platformIdInput');
    const displayNameInput = document.getElementById('displayNameInput');

    function toggleClientPicker() {
      const val = platformSelect?.value || 'manual';
      if (val === 'client') {
        clientPickerWrap.style.display = '';
        platformIdInput.readOnly = true;
        platformIdInput.placeholder = 'wybierz z „Szukaj klienta”';
      } else {
        clientPickerWrap.style.display = 'none';
        clientResults.style.display = 'none';
        platformIdInput.readOnly = false;
        platformIdInput.placeholder = 'np. client_id lub PSID';
        clientIdHidden.value = '';
        clientSearch.value = '';
      }
    }
    if (platformSelect) {
      platformSelect.addEventListener('change', toggleClientPicker);
      toggleClientPicker();
    }

    function debounce(fn, wait = 250) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
      };
    }

    async function searchClients(q) {
      if (!q || q.length < 2) {
        clientResults.style.display = 'none';
        clientResults.innerHTML = '';
        return;
      }
      const url = 'api/search_clients.php?q=' + encodeURIComponent(q) + '&draw_id=' + encodeURIComponent(drawId);
      try {
        const res = await fetch(url, {
          headers: {
            'Accept': 'application/json'
          }
        });
        const text = await res.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          console.error('search_clients.php zwrócił nie-JSON:', text);
          clientResults.style.display = 'none';
          clientResults.innerHTML = '';
          return;
        }
        if (data && data.error) {
          console.error('search_clients.php błąd:', data.error);
          clientResults.style.display = 'none';
          clientResults.innerHTML = '';
          return;
        }
        renderClientResults(Array.isArray(data) ? data : (data.results || []));
      } catch (e) {
        console.error('search_clients.php fetch error:', e);
        clientResults.style.display = 'none';
        clientResults.innerHTML = '';
      }
    }


    function renderClientResults(items) {
      if (!items.length) {
        clientResults.style.display = 'none';
        clientResults.innerHTML = '';
        return;
      }
      clientResults.innerHTML = items.map(c => {
        const title = (c.name && c.name.trim()) ? c.name : ('Klient #' + c.id);
        const meta = [c.email, c.phone, c.token].filter(Boolean).join(' • ');
        return `<div class="item" data-id="${c.id}" data-name="${(title||'').replace(/"/g,'&quot;')}">
                <div class="title">${title}</div>
                <div class="meta">${meta}</div>
              </div>`;
      }).join('');
      clientResults.style.display = 'block';
      clientResults.querySelectorAll('.item').forEach(el => {
        el.addEventListener('click', () => {
          const id = el.getAttribute('data-id');
          const name = el.getAttribute('data-name') || '';
          clientIdHidden.value = id;
          platformIdInput.value = id; // to id klienta pójdzie jako platform_id
          displayNameInput.value = name; // opcjonalnie; backend i tak dociągnie jeśli puste
          clientResults.style.display = 'none';
          clientSearch.value = name;
        });
      });
    }

    if (clientSearch) {
      clientSearch.addEventListener('input', debounce(() => searchClients(clientSearch.value), 250));
      document.addEventListener('click', (e) => {
        if (!clientPickerWrap.contains(e.target)) {
          clientResults.style.display = 'none';
        }
      });
    }

    // ==== SLOT MACHINE (3 reels) ====
    const modal = document.getElementById('drawModal');
    const statusBox = document.getElementById('drawStatus');
    const btnClose = document.getElementById('drawCloseBtn');
    const reel1 = document.getElementById('reel1');
    const reel2 = document.getElementById('reel2');
    const reel3 = document.getElementById('reel3');

    function openModal() {
      if (modal) modal.style.display = 'block';
    }

    function closeModal() {
      if (modal) modal.style.display = 'none';
    }
    if (btnClose) btnClose.addEventListener('click', closeModal);

    function getEntriesFromTable() {
      const rows = document.querySelectorAll('#entriesTable tbody tr');
      const list = [];
      rows.forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (tds.length >= 2) {
          const name = (tds[1].textContent || '').trim();
          if (name) list.push(name);
        }
      });
      return list.reverse(); // tabela DESC → chcemy rosnąco
    }

    function buildReel(el, names, winner, loops = 8, tail = 12) {
      el.innerHTML = '';
      const cells = [];
      for (let i = 0; i < loops; i++) names.forEach(n => cells.push(n));
      if (!names.includes(winner)) cells.push(winner);
      for (let i = 0; i < Math.min(tail, names.length); i++) cells.push(names[i % names.length]);
      cells.push(winner);
      const frag = document.createDocumentFragment();
      cells.forEach(txt => {
        const div = document.createElement('div');
        div.className = 'cell';
        div.textContent = txt;
        frag.appendChild(div);
      });
      el.appendChild(frag);
      return cells.length;
    }

    function animateToWinner(el, totalCells, durationMs = 3800) {
      const cellH = 48;
      const targetIndex = totalCells - 1;
      const targetY = targetIndex * cellH - (120 - (cellH / 2)); // align do środka
      return new Promise(resolve => {
        const start = performance.now();
        const startY = 0;
        const delta = targetY - startY;
        const easeOutCubic = t => 1 - Math.pow(1 - t, 3);

        function frame(now) {
          const p = Math.min(1, (now - start) / durationMs);
          const y = startY + delta * easeOutCubic(p);
          el.style.transform = `translateY(${-y}px)`;
          if (p < 1) requestAnimationFrame(frame);
          else resolve();
        }
        requestAnimationFrame(frame);
      });
    }

    function confetti(duration = 1200) {
      const end = Date.now() + duration;
      (function frame() {
        const angle = Math.random() * 360,
          spread = 45 + Math.random() * 45;
        const count = 10 + Math.random() * 20;
        for (let i = 0; i < count; i++) {
          const s = document.createElement('span');
          s.textContent = '🎊';
          s.style.position = 'fixed';
          s.style.left = (Math.random() * 100) + 'vw';
          s.style.top = '-20px';
          s.style.zIndex = 9999;
          s.style.fontSize = (16 + Math.random() * 12) + 'px';
          s.style.transition = 'transform 1.2s linear, opacity 1.2s ease';
          document.body.appendChild(s);
          requestAnimationFrame(() => {
            s.style.transform = `translateY(${80+Math.random()*100}vh) rotate(${angle+(Math.random()*spread-spread/2)}deg)`;
            s.style.opacity = '0';
          });
          setTimeout(() => s.remove(), 1400);
        }
        if (Date.now() < end) requestAnimationFrame(frame);
      })();
    }

    const btnDraw = document.getElementById('btn-draw');
    if (btnDraw) btnDraw.addEventListener('click', async () => {
      openModal();
      if (statusBox) statusBox.textContent = 'Pobieram zwycięzcę z serwera…';

      // 1) Pobierz zwycięzcę (deterministycznie)
      const r = await fetch('api/draw.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: new URLSearchParams({
          id: drawId
        })
      });
      const raw = await r.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        if (statusBox) statusBox.textContent = 'Błąd odpowiedzi serwera (draw.php). Sprawdź konsolę.';
        console.error('draw.php zwrócił nie-JSON:', raw);
        return;
      }
      if (!data.success) {
        if (statusBox) statusBox.textContent = data.error ? ('Błąd: ' + data.error) : 'Błąd losowania';
        return;
      }
      // ... dalej używaj "data" zamiast "r"


      const winner = r.winner?.display_name || '(???)';
      if (statusBox) statusBox.textContent = 'Kręcę bębnem…';

      const names = getEntriesFromTable();
      if (!names.includes(winner)) names.push(winner);

      const total1 = buildReel(reel1, names, winner, 7, 10);
      const total2 = buildReel(reel2, names, winner, 9, 12);
      const total3 = buildReel(reel3, names, winner, 11, 14);

      await Promise.all([
        animateToWinner(reel1, total1, 3200),
        animateToWinner(reel2, total2, 3800),
        animateToWinner(reel3, total3, 4400),
      ]);

      confetti(1400);
      if (statusBox) statusBox.textContent = '🎉 Zwycięzca: ' + winner;
      setTimeout(() => location.reload(), 1200);
    });
  })();
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php';
