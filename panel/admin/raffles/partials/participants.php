<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/helpers.php';
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$session_owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$draw_id          = (int)($_GET['id'] ?? 0);

if ($draw_id <= 0) {
  echo '<div class="p-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded">Brak ID losowania.</div>';
  return;
}

/* Pobierz losowanie po ID (bez filtra ownera) */
$qd = $pdo->prepare("SELECT id, owner_id, status, keyword FROM draws WHERE id=:id LIMIT 1");
$qd->execute(['id'=>$draw_id]);
$draw = $qd->fetch(PDO::FETCH_ASSOC);

if (!$draw) {
  echo '<div class="p-4 text-sm text-red-700 bg-red-50 border border-red-200 rounded">Nie znaleziono losowania (ID='.h($draw_id).').</div>';
  return;
}

/* owner do filtrów – jeśli w sesji pusty, bierz z losowania */
$owner_id = $session_owner_id > 0 ? $session_owner_id : (int)$draw['owner_id'];
$canEdit  = ($draw['status'] === 'arming');

/* Ostatnie 500 zgłoszeń dla losowania */
$se = $pdo->prepare("
  SELECT id, platform, platform_id, display_name, source, weight, created_at
  FROM draw_entries
  WHERE draw_id = :did
  ORDER BY id DESC
  LIMIT 500
");
$se->execute(['did'=>$draw_id]);
$rows = $se->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="space-y-5">
  <!-- Info -->
  <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sky-800 text-sm">
    <b>Automatyczne zgłoszenia:</b>
    jeśli ustawisz słowo kluczowe <code class="font-mono"><?= h($draw['keyword'] ?: '—') ?></code>,
    wpisy z czatu wpadają automatycznie. Poniżej dodasz osoby ręcznie — z bazy klientów lub „z palca”.
  </div>

  <!-- Dodawanie z bazy klientów -->
  <div class="rounded-xl border border-slate-200 bg-white p-4">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-slate-700 mb-3">Dodaj z bazy klientów</h3>
      <button id="btnOpenNewClient" class="text-sm text-slate-600 hover:text-slate-900 <?= $canEdit?'':'opacity-40' ?>">
        + Nowy klient
      </button>
    </div>
    <div class="relative">
      <input id="clientSearch" type="text" placeholder="Wpisz imię i nazwisko"
             class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
             autocomplete="off">
      <div id="searchResults"
           class="hidden absolute left-0 right-0 mt-1 rounded-md border border-slate-200 bg-white shadow-lg z-50 max-h-72 overflow-auto"></div>
    </div>
    <div class="mt-3 flex justify-end">
      <button id="btnAddClient" class="px-4 py-2 rounded-lg text-sm font-medium bg-emerald-600 text-white hover:bg-emerald-700 <?= $canEdit?'':'opacity-40' ?>">
        Dodaj wybranego klienta
      </button>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      Na liście: <em>Imię Nazwisko</em> + małym druczkiem „klient: {id}”.
    </p>
  </div>

  <!-- Dodawanie „z palca” -->
  <div class="rounded-xl border border-slate-200 bg-white p-4">
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Dodaj ręcznie (bez klienta)</h3>
    <form id="frmAddEntry" class="grid grid-cols-1 md:grid-cols-4 gap-3" onsubmit="return false;">
      <input type="hidden" name="draw_id" value="<?= (int)$draw_id ?>">
      <div class="md:col-span-2">
        <label class="block text-xs text-slate-500 mb-1">Wyświetlana nazwa</label>
        <input name="display_name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
               placeholder="np. Jan Kowalski" required>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">Platforma</label>
        <input name="platform" value="manual" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">ID na platformie (opcjonalnie)</label>
        <input name="platform_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="np. fb_123...">
      </div>
      <div class="md:col-span-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">* Backend blokuje poza etapem <b>Zbieranie zgłoszeń</b>.</p>
        <button id="btnAddManual" type="button"
          class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-900 text-white hover:bg-slate-800 <?= $canEdit ? '' : 'opacity-40' ?>">
          Dodaj ➕
        </button>
      </div>
    </form>
    <div id="addEntryMsg" class="mt-2 text-sm"></div>
  </div>

  <!-- Lista -->
  <div class="rounded-xl border border-slate-200 bg-white">
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
      <h3 class="text-sm font-semibold text-slate-700">Lista uczestników</h3>
      <span class="text-xs text-slate-500">Łącznie: <?= count($rows) ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-4 py-2">Nazwa</th>
            <th class="px-4 py-2">Platforma</th>
            <th class="px-4 py-2">ID</th>
            <th class="px-4 py-2">Źródło</th>
            <th class="px-4 py-2">Waga</th>
            <th class="px-4 py-2 w-40 text-right">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">Brak uczestników.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr class="border-t border-slate-100">
              <td class="px-4 py-2 text-slate-900"><?= h($r['display_name']) ?></td>
              <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700"><?= h($r['platform']) ?></span></td>
              <td class="px-4 py-2 text-slate-500"><?= $r['platform_id'] ? h($r['platform_id']) : '—' ?></td>
              <td class="px-4 py-2 text-slate-500"><?= h($r['source']) ?></td>
              <td class="px-4 py-2"><?= (int)$r['weight'] ?></td>
              <td class="px-4 py-2">
                <div class="flex items-center justify-end gap-2">
                  <button class="btn-del px-2 py-1 rounded border border-slate-300 text-slate-700 hover:bg-slate-50 <?= $canEdit?'':'opacity-40' ?>"
                          data-id="<?= (int)$r['id'] ?>">🗑 Usuń</button>
                  <button class="btn-ban px-2 py-1 rounded border border-rose-300 text-rose-800 hover:bg-rose-50 <?= $canEdit?'':'opacity-40' ?>"
                          data-id="<?= (int)$r['id'] ?>"
                          data-platform="<?= h($r['platform']) ?>"
                          data-platform-id="<?= h($r['platform_id']) ?>">🚫 Ban</button>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Nowy klient -->
<div id="modalNewClient" class="fixed inset-0 hidden items-center justify-center z-[100]">
  <div class="absolute inset-0 bg-black/30"></div>
  <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-base font-semibold">Nowy klient</h3>
      <button id="btnCloseNewClient" class="text-slate-500 hover:text-slate-900">✕</button>
    </div>
    <form id="frmNewClient" class="space-y-3">
      <div>
        <label class="block text-xs text-slate-500 mb-1">Imię i nazwisko / Nazwa</label>
        <input name="name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" required>
      </div>
      <div class="pt-2 flex items-center justify-end gap-2">
        <button type="button" id="btnCancelNewClient" class="px-3 py-2 rounded border border-slate-300 text-slate-700">Anuluj</button>
        <button class="px-4 py-2 rounded bg-slate-900 text-white hover:bg-slate-800">Zapisz</button>
      </div>
      <div id="newClientMsg" class="text-sm mt-1"></div>
    </form>
  </div>
</div>

<script>
window.initParticipants = function(DID){
  const wrap = document.getElementById('tabContent');
  const reload = async () => {
    if (!wrap) return;
    wrap.innerHTML = '<div class="text-sm text-slate-500">Odświeżam…</div>';
    const html = await (await fetch('partials/participants.php?id='+DID)).text();
    wrap.innerHTML = html;
    if (typeof window.initParticipants === 'function') window.initParticipants(DID);
  };

  /* ---------- AUTOCOMPLETE (clients) ---------- */
  const input = document.getElementById('clientSearch');
  const box   = document.getElementById('searchResults');
  const btnClient = document.getElementById('btnAddClient');
  let selectedClientId = null, idx = -1, items = [], timer;

  function clearBox(){ if(!box) return; box.innerHTML=''; box.classList.add('hidden'); idx=-1; items=[]; }
  function render(itemsData){
    if(!box) return;
    box.innerHTML=''; items=[];
    (itemsData||[]).forEach((item,i)=>{
      const el = document.createElement('div');
      el.className = 'px-3 py-2 hover:bg-slate-100 cursor-pointer';
      el.innerHTML = `<div>${item.name}</div><div class="text-xs text-slate-500">klient: ${item.id}</div>`;
      el.addEventListener('mousedown', (e)=>{ e.preventDefault(); pick(i); });
      box.appendChild(el); items.push({el, ...item});
    });
    box.classList.toggle('hidden', items.length===0);
    idx = items.length?0:-1; highlight();
  }
  function highlight(){ items.forEach((it,k)=> it.el.classList.toggle('bg-slate-100', k===idx)); }
  function pick(i){ if(i<0||i>=items.length) return; input.value = items[i].name; selectedClientId = items[i].id; clearBox(); }

  if (input){
    input.addEventListener('input', ()=>{
      selectedClientId = null;
      clearTimeout(timer);
      const q = input.value.trim();
      if(q.length<2){ clearBox(); return; }
      timer = setTimeout(async ()=>{
        try{
          const res = await fetch(`api/search_clients.php?draw_id=${encodeURIComponent(DID)}&q=${encodeURIComponent(q)}`);
          const data = await res.json();
          render(data || []);
        }catch{ clearBox(); }
      }, 180);
    });
    input.addEventListener('keydown', (e)=>{
      if(!box || box.classList.contains('hidden')) return;
      if(e.key==='ArrowDown'){ idx = Math.min(idx+1, items.length-1); highlight(); e.preventDefault(); }
      else if(e.key==='ArrowUp'){ idx = Math.max(idx-1, 0); highlight(); e.preventDefault(); }
      else if(e.key==='Enter'){ if(idx>=0){ pick(idx); e.preventDefault(); } }
      else if(e.key==='Escape'){ clearBox(); }
    });
    document.addEventListener('click', (e)=>{ if(box && !box.contains(e.target) && e.target!==input){ clearBox(); } });
  }

  if (btnClient){
    btnClient.addEventListener('click', async ()=>{
      if(!selectedClientId){ alert('Wybierz klienta z listy.'); return; }
      const fd = new FormData();
      fd.set('draw_id', String(DID));
      fd.set('display_name', (input?.value || '').trim());
      fd.set('platform', 'client');
      fd.set('platform_id', String(selectedClientId));
      try{
        const resp = await fetch('api/add_entry.php', { method:'POST', body: fd });
        const data = await resp.json();
        if(data.success){ reload(); } else { alert(data.error || 'Błąd'); }
      }catch{ alert('Błąd sieci.'); }
    });
  }

  /* ---------- Dodawanie „z palca” ---------- */
  const form = document.getElementById('frmAddEntry');
  const btnManual = document.getElementById('btnAddManual');
  const msg  = document.getElementById('addEntryMsg');

  if (form && btnManual){
    form.addEventListener('submit', (e)=> e.preventDefault()); // safety
    btnManual.addEventListener('click', async ()=>{
      msg && (msg.textContent = 'Dodaję…');
      const fd = new FormData(form);
      fd.set('draw_id', String(DID)); // KLUCZOWE
      try{
        const resp = await fetch('api/add_entry.php', { method:'POST', body: fd });
        const data = await resp.json();
        if(data.success){ reload(); }
        else { msg && (msg.textContent = data.error || 'Błąd'); msg && msg.classList.add('text-red-600'); }
      }catch{
        msg && (msg.textContent = 'Błąd sieci.'); msg && msg.classList.add('text-red-600');
      }
    });
  }

  /* ---------- Usuń / Ban ---------- */
  document.querySelectorAll('.btn-del').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if(!confirm('Usunąć zgłoszenie?')) return;
      const fd = new FormData();
      fd.set('entry_id', btn.dataset.id);
      fd.set('draw_id', String(DID));
      const res = await fetch('api/delete_entry.php', { method:'POST', body: fd }).then(r=>r.json()).catch(()=>({}));
      if(res?.success){ reload(); } else { alert(res?.error || 'Błąd'); }
    });
  });
  document.querySelectorAll('.btn-ban').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      if(!confirm('Dodać do ban-listy i usunąć z listy?')) return;
      const fd = new FormData();
      fd.set('entry_id', btn.dataset.id);
      fd.set('draw_id', String(DID));
      fd.set('platform', btn.dataset.platform || '');
      fd.set('platform_id', btn.dataset.platformId || btn.getAttribute('data-platform-id') || '');
      fd.set('reason', 'Panel: ręczny ban');
      const res = await fetch('api/ban_entry.php', { method:'POST', body: fd }).then(r=>r.json()).catch(()=>({}));
      if(res?.success){ reload(); } else { alert(res?.error || 'Błąd'); }
    });
  });

  /* ---------- Modal: Nowy klient ---------- */
  const modal = document.getElementById('modalNewClient');
  const openBtn = document.getElementById('btnOpenNewClient');
  const closeBtn = document.getElementById('btnCloseNewClient');
  const cancelBtn = document.getElementById('btnCancelNewClient');
  const frmNew = document.getElementById('frmNewClient');
  const newMsg = document.getElementById('newClientMsg');

  function openModal(){ modal?.classList.remove('hidden'); modal?.classList.add('flex'); newMsg && (newMsg.textContent=''); }
  function closeModal(){ modal?.classList.add('hidden'); modal?.classList.remove('flex'); newMsg && (newMsg.textContent=''); frmNew?.reset(); }

  openBtn?.addEventListener('click', openModal);
  closeBtn?.addEventListener('click', closeModal);
  cancelBtn?.addEventListener('click', closeModal);

  frmNew?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if(!newMsg) return;
    newMsg.textContent = 'Zapisuję…';
    try{
      const formData = new FormData(frmNew);
      formData.append('draw_id', String(DID)); // fallback ownera po draw_id
      const resp = await fetch('api/create_client.php', { method:'POST', body: formData });
      const data = await resp.json();
      if(data.success){
        const input = document.getElementById('clientSearch');
        if(input){ input.value = data.name; }
        selectedClientId = data.id;
        closeModal();
      }else{
        newMsg.textContent = data.error || 'Błąd';
        newMsg.classList.add('text-red-600');
      }
    }catch{
      newMsg.textContent = 'Błąd sieci.'; newMsg.classList.add('text-red-600');
    }
  });
};
</script>
