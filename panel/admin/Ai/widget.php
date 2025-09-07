<?php
// admin/ai/widget.php — pływający dymek AI (Olaj V4)
// Drop-in: include na końcu dowolnej strony admina.
// Wymaga: zalogowanej sesji (owner_id, user_id) i endpointu admin/ai/ajax_chat.php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
$userId  = (int)($_SESSION['user']['id'] ?? 0);
if ($ownerId <= 0 || $userId <= 0) {
    // Brak dostępu — nie renderuj widgetu
    return;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
?>
<style>
  /* ─── Bubble button ───────────────────────────────────────── */
  #olaj-ai-bubble {
    position: fixed; right: 18px; bottom: 18px; z-index: 9999;
    width: 58px; height: 58px; border-radius: 999px;
    background: #111827; color: #fff; display:flex; align-items:center; justify-content:center;
    box-shadow: 0 10px 24px rgba(0,0,0,.25); cursor: pointer; user-select: none;
    transition: transform .15s ease, box-shadow .15s ease;
  }
  #olaj-ai-bubble:hover { transform: translateY(-1px); box-shadow: 0 12px 28px rgba(0,0,0,.3); }
  #olaj-ai-bubble svg { width: 26px; height: 26px; }

  /* ─── Panel czatu (dymek) ─────────────────────────────────── */
  #olaj-ai-panel {
    position: fixed; right: 18px; bottom: 86px; z-index: 10000; width: 360px; max-width: calc(100vw - 36px);
    background: #fff; border-radius: 16px; box-shadow: 0 24px 56px rgba(0,0,0,.35); overflow: hidden;
    transform-origin: 100% 100%; opacity: 0; pointer-events: none; transform: scale(.98) translateY(8px);
    transition: opacity .18s ease, transform .18s ease;
  }
  #olaj-ai-panel.open { opacity: 1; pointer-events: auto; transform: scale(1) translateY(0); }

  .olaj-ai-head {
    display:flex; align-items:center; gap:10px; padding:10px 12px; background:#111827; color:#fff;
    cursor: move; user-select: none;
  }
  .olaj-ai-head .title { font-weight: 600; font-size: 14px; }
  .olaj-ai-head .meta { opacity:.7; font-size:12px; margin-left:auto; }

  .olaj-ai-body { background: #f9fafb; }
  .olaj-ai-scroll { height: 360px; overflow:auto; padding: 12px; }

  .olaj-ai-bubble { max-width: 75%; border-radius: 14px; padding: 8px 12px; margin: 6px 0; word-wrap: break-word; white-space: pre-wrap;}
  .olaj-ai-bubble.user { margin-left:auto; background:#1f2937; color:#fff; }
  .olaj-ai-bubble.assistant { margin-right:auto; background:#ffffff; color:#111827; box-shadow: 0 1px 0 rgba(0,0,0,.06) inset; }
  .olaj-ai-time { font-size: 11px; opacity:.6; margin-top: 3px; }

  .olaj-ai-input { display:flex; gap:8px; padding: 10px; background:#fff; border-top:1px solid #e5e7eb; }
  .olaj-ai-input textarea {
    flex:1; resize:none; max-height: 120px; min-height: 38px; padding:8px 10px;
    border:1px solid #e5e7eb; border-radius:10px; outline:none;
    font-size: 14px; line-height: 1.3; background:#fff;
  }
  .olaj-ai-input button {
    background:#2563eb; color:#fff; border:none; border-radius:10px; padding:0 14px; font-weight:600; cursor:pointer;
  }
  .olaj-ai-input button:hover { background:#1d4ed8; }
  .olaj-ai-quick { display:flex; gap:6px; padding: 8px 10px; background:#fff; border-top:1px solid #f1f5f9; }
  .olaj-ai-quick button { font-size:12px; padding:6px 8px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; cursor:pointer; }
  .olaj-ai-quick button:hover { background:#f3f4f6; }
  .olaj-ai-footer { display:flex; justify-content:space-between; align-items:center; padding:6px 10px; font-size:11px; color:#6b7280; background:#f8fafc; }
  .olaj-ai-link { text-decoration: underline; cursor:pointer; }
</style>

<div id="olaj-ai-bubble" title="AI chat">
  <!-- Ikona dymka -->
  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M8 10h8M8 14h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    <path d="M12 20c-5.523 0-10-3.582-10-8s4.477-8 10-8 10 3.582 10 8c0 1.77-.61 3.41-1.67 4.82-.19.25-.27.57-.19.88l.51 2.03c.2.82-.55 1.54-1.36 1.31l-2.07-.59c-.24-.07-.5-.04-.71.09A11.1 11.1 0 0 1 12 20z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
  </svg>
</div>

<div id="olaj-ai-panel" aria-hidden="true">
  <div class="olaj-ai-head" id="olaj-ai-drag">
    <div class="title">AI chat</div>
    <div class="meta">#<?= (int)$ownerId ?> · #<?= (int)$userId ?></div>
  </div>
  <div class="olaj-ai-body">
    <div class="olaj-ai-scroll" id="olaj-ai-scroll"></div>

    <div class="olaj-ai-quick">
      <button type="button" data-snippet="Stwórz krótki opis produktu w 3–4 zdaniach: ">Opis</button>
      <button type="button" data-snippet="Zaproponuj meta title, description i 6 słów kluczowych dla: ">SEO</button>
      <button type="button" data-snippet="Zaproponuj szablon CW dla eventu 'cart.item_added' w stylu Olaj V4: ">Szablon CW</button>
      <button type="button" id="olaj-ai-clear">Wyczyść</button>
    </div>

    <div class="olaj-ai-input">
      <textarea id="olaj-ai-input" placeholder="Napisz do asystenta… (Shift+Enter = nowa linia)"></textarea>
      <button id="olaj-ai-send">Wyślij</button>
    </div>

    <div class="olaj-ai-footer">
      <span>Ollama/OpenAI (auto)</span>
      <span class="olaj-ai-link" id="olaj-ai-open-full">Otwórz pełny widok</span>
    </div>
  </div>
</div>

<script>
(function(){
  const bubble = document.getElementById('olaj-ai-bubble');
  const panel  = document.getElementById('olaj-ai-panel');
  const scroll = document.getElementById('olaj-ai-scroll');
  const input  = document.getElementById('olaj-ai-input');
  const sendBtn= document.getElementById('olaj-ai-send');
  const clearBtn = document.getElementById('olaj-ai-clear');
  const openFull = document.getElementById('olaj-ai-open-full');
  const dragBar  = document.getElementById('olaj-ai-drag');

  const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
(async ()=>{
  try {
    const r = await fetch('/admin/ai/ajax_chat.php?action=history',{credentials:'same-origin'});
    // opcjonalnie możesz zrobić swój backendowy healthcheck do /api/version
  } catch(e) {/* zignoruj */}
})();

  function esc(s){return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));}
  function timeNow(){ const d=new Date(); return d.toLocaleTimeString(); }

  function bubbleMsg(role, content, time){
    const el = document.createElement('div');
    el.className = 'olaj-ai-bubble ' + (role==='user'?'user':'assistant');
    el.innerHTML = '<div>'+esc(content)+'</div><div class="olaj-ai-time">'+esc(time||'')+'</div>';
    scroll.appendChild(el);
    scroll.scrollTop = scroll.scrollHeight;
  }

  async function loadHistory(){
    try{
      const r = await fetch('/admin/ai/ajax_chat.php?action=history', {credentials:'same-origin'});
      const j = await r.json();
      scroll.innerHTML = '';
      if (j.ok && Array.isArray(j.items)) {
        j.items.forEach(it => bubbleMsg(it.role, it.message, it.created_at));
      } else {
        bubbleMsg('assistant','Nie udało się pobrać historii.','');
      }
    }catch(e){
      bubbleMsg('assistant','Błąd ładowania historii.','');
    }
  }

  async function sendMsg(text){
    const fd = new FormData();
    fd.set('csrf', csrf);
    fd.set('message', text);
    try{
      const r = await fetch('/admin/ai/ajax_chat.php', {method:'POST', body:fd, credentials:'same-origin'});
      const j = await r.json();
      if (j.ok && j.reply) {
        bubbleMsg('assistant', j.reply.content, timeNow());
      } else {
        bubbleMsg('assistant', 'Błąd: ' + (j.error||'nieznany'), '');
      }
    }catch(e){
      bubbleMsg('assistant', 'Błąd sieci: ' + (e.message||e), '');
    }
  }

  // Toggle
  function openPanel(){
    panel.classList.add('open');
    panel.setAttribute('aria-hidden','false');
    loadHistory();
    setTimeout(()=>input.focus(), 0);
  }
  function closePanel(){
    panel.classList.remove('open');
    panel.setAttribute('aria-hidden','true');
  }
  bubble.addEventListener('click', ()=>{
    if (panel.classList.contains('open')) closePanel(); else openPanel();
  });

  // Quick snippets
  document.querySelectorAll('.olaj-ai-quick [data-snippet]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      input.value = btn.getAttribute('data-snippet');
      input.focus();
    });
  });

  // Send
  sendBtn.addEventListener('click', ()=>{
    const txt = input.value.trim();
    if(!txt) return;
    bubbleMsg('user', txt, timeNow());
    input.value='';
    sendMsg(txt);
  });
  input.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendBtn.click();
    }
  });

  // Clear
  clearBtn.addEventListener('click', async ()=>{
    if (!confirm('Wyczyścić historię chatu?')) return;
    const r = await fetch('/admin/ai/ajax_chat.php?action=clear', {credentials:'same-origin'});
    const j = await r.json();
    if (j.ok) { scroll.innerHTML=''; } else { alert('Nie udało się: ' + (j.error||'')); }
  });

  // Pełny widok
  openFull.addEventListener('click', ()=>{ window.location.href = '/admin/ai/chat.php'; });

  // Drag & persist position
  (function enableDrag(){
    let isDown=false, startX=0, startY=0, startL=0, startT=0;

    function px(n){return n+'px';}
    function clamp(v, min, max){return Math.max(min, Math.min(max, v));}

    // Przywróć pozycję
    try{
      const saved = JSON.parse(localStorage.getItem('olajAiPanelPos')||'{}');
      if (saved && typeof saved.l==='number' && typeof saved.t==='number') {
        panel.style.right = 'auto';
        panel.style.bottom= 'auto';
        panel.style.left  = px(saved.l);
        panel.style.top   = px(saved.t);
      }
    }catch{}

    dragBar.addEventListener('mousedown', (e)=>{
      isDown = true;
      const rect = panel.getBoundingClientRect();
      startX = e.clientX; startY = e.clientY;
      startL = rect.left; startT = rect.top;
      document.body.style.userSelect='none';
    });
    window.addEventListener('mousemove', (e)=>{
      if(!isDown) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      let L = startL + dx;
      let T = startT + dy;
      const maxL = window.innerWidth - panel.offsetWidth - 6;
      const maxT = window.innerHeight - panel.offsetHeight - 6;
      L = clamp(L, 6, maxL);
      T = clamp(T, 6, maxT);
      panel.style.left = px(L);
      panel.style.top  = px(T);
      panel.style.right = 'auto';
      panel.style.bottom= 'auto';
    });
    window.addEventListener('mouseup', ()=>{
      if(!isDown) return;
      isDown=false;
      document.body.style.userSelect='';
      // zapisz
      try{
        const rect = panel.getBoundingClientRect();
        localStorage.setItem('olajAiPanelPos', JSON.stringify({l: rect.left, t: rect.top}));
      }catch{}
    });
  })();

})();
</script>
