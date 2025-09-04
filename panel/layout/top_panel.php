<?php // layout/top_panel.php — V4 topbar (no overflow, grid, desktop-friendly) 
?>
<header id="topbar"
  style="
    position:fixed; top:0; left:0; right:0;
    height:56px;
    display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:12px;
    padding:8px 16px;
    background:#fff; border-bottom:1px solid #e5e7eb; box-shadow:0 1px 0 rgba(0,0,0,.04);
    z-index:1000;
    box-sizing:border-box; max-width:100vw;
    overflow-x:hidden; overflow-y:visible;
  "
  role="banner">
  <!-- LEWA -->
  <div style="display:flex; align-items:center; gap:12px; min-width:0;">
    <button
      type="button" id="hamburger"
      aria-label="Przełącz menu boczne" aria-controls="sidebar" aria-expanded="false"
      style="background:none; border:0; cursor:pointer; width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center;">
      <i data-lucide="menu"></i>
    </button>

    <div style="font-size:1.1rem; font-weight:700; color:#0f172a; white-space:nowrap;">
      <span style="color:#2563eb;">Olaj.pl</span> Panel
    </div>
  </div>

  <!-- ŚRODEK (MARQUEE) -->
  <div style="min-width:0; overflow:hidden;">
    <div id="marquee-strip"
      style="
           width:100%; overflow:hidden; white-space:nowrap; color:#334155; font-size:.85rem; line-height:1;
           mask-image: linear-gradient(90deg, transparent 0, #000 24px, #000 calc(100% - 24px), transparent 100%);
           -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 24px, #000 calc(100% - 24px), transparent 100%);
         ">
      <div id="marquee-content"
        style="display:inline-block; animation:scroll-left 25s linear infinite; will-change:transform;">⏳ Ładowanie powiadomień...</div>
    </div>
  </div>

  <!-- PRAWA -->
  <div style="display:flex; align-items:center; gap:10px; min-width:0;">
    <a href="/admin/orders/" title="Zamówienia" style="display:inline-flex; align-items:center;"><i data-lucide="shopping-cart"></i></a>
    <a href="/live/view.php" title="Start transmisji" style="display:inline-flex; align-items:center;"><i data-lucide="broadcast"></i></a>

    <div id="bell" title="Powiadomienia" style="position:relative; cursor:pointer; display:inline-flex; align-items:center; justify-content:center;">
      <i data-lucide="bell"></i>
      <span id="bell-badge" style="position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:12px; padding:2px 6px; border-radius:10px; display:none;">0</span>
    </div>

    <!-- Szukaj — elastyczna, nie rozsadzi układu -->
    <form method="GET" action="/admin/search.php" style="margin:0; flex:0 1 240px; min-width:160px; display:flex;">
      <input type="text" name="q" placeholder="Szukaj..."
        style="padding:6px 10px; border:1px solid #e5e7eb; border-radius:8px; outline:0; width:100%; min-width:0;">
    </form>

    <button id="darkModeBtn" type="button" title="Przełącz tryb ciemny"
      style="border:1px solid #e5e7eb; background:#f8fafc; padding:6px 10px; border-radius:10px; cursor:pointer;">
      <i data-lucide="moon"></i>
    </button>

    <div id="user-menu" style="position:relative; min-width:0;">
      <button type="button" id="userMenuBtn" aria-haspopup="true" aria-expanded="false"
        style="cursor:pointer; color:#0f172a; background:none; border:0; padding:6px 8px; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        <?= htmlspecialchars($_SESSION['user']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?> ▾
      </button>
      <div id="dropdown"
        style="display:none; position:absolute; right:0; top:calc(100% + 6px); background:#fff; border:1px solid #e5e7eb; border-radius:8px; min-width:200px; z-index:1100; box-shadow:0 8px 24px rgba(0,0,0,.08);">
        <a href="/admin/settings/index.php" style="display:block; padding:10px 12px; color:#0f172a; text-decoration:none;">⚙️ Ustawienia</a>
        <a href="/auth/logout.php" style="display:block; padding:10px 12px; color:#ef4444; text-decoration:none;">🚪 Wyloguj</a>
      </div>
    </div>
  </div>
</header>

<style>
  @keyframes scroll-left {
    0% {
      transform: translateX(100%);
    }

    100% {
      transform: translateX(-100%);
    }
  }

  #marquee-strip:hover #marquee-content {
    animation-play-state: paused;
  }

  .marq-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .2rem .5rem;
    border: 1px solid #e5e7eb;
    border-radius: 999px;
    margin-right: .5rem;
  }

  .marq-pill .ico {
    display: inline-flex;
  }

  /* Dark mode */
  .dark-mode #topbar {
    background: #0f172a !important;
    border-color: #1f2937 !important;
  }

  .dark-mode #topbar * {
    color: #e5e7eb !important;
  }

  .dark-mode .marq-pill {
    border-color: #334155;
  }

  /* Responsywność: mniejsze paddingi, tłumienie marquee gdy mało miejsca */
  @media (max-width: 1280px) {
    #topbar {
      padding-left: 12px !important;
      padding-right: 12px !important;
    }
  }

  @media (max-width: 1100px) {
    #marquee-strip {
      display: none;
    }

    /* gdy robi się ciasno, wyłącz pasek */
  }
</style>

<script src="https://unpkg.com/lucide@latest" defer></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    if (window.lucide && lucide.createIcons) lucide.createIcons();

    // User dropdown
    const dd = document.getElementById('dropdown');
    const btn = document.getElementById('userMenuBtn');
    if (btn && dd) {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = dd.style.display === 'block';
        dd.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      });
      document.addEventListener('click', () => {
        dd.style.display = 'none';
        btn.setAttribute('aria-expanded', 'false');
      });
    }

    // Hamburger sync
    const hamburger = document.getElementById('hamburger');

    function syncAria() {
      const expanded = document.body.classList.contains('sidebar-open') || !document.body.classList.contains('sidebar-collapsed');
      hamburger.setAttribute('aria-expanded', String(expanded));
    }

    function fireToggle() {
      window.dispatchEvent(new CustomEvent('toggle-sidebar'));
      syncAria();
    }
    if (hamburger) {
      hamburger.addEventListener('click', (e) => {
        e.preventDefault();
        fireToggle();
      });
      window.addEventListener('toggle-sidebar', syncAria);
      window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setTimeout(syncAria, 0);
      });
      syncAria();
    }

    // Powiadomienia (jak u Ciebie – bez zmian funkcjonalnych)
    const marquee = document.getElementById('marquee-content');
    const badge = document.getElementById('bell-badge');
    const bell = document.getElementById('bell');
    const NOTIF_DEBUG = localStorage.getItem('notifDebug') === '1';
    const notifCandidates = ['/admin/api/notifications.php?format=objects', '/api/notifications.php?format=objects'].map(u => NOTIF_DEBUG ? u + '&debug=1' : u);
    const countsCandidates = ['/admin/api/notifications_counts.php', '/api/notifications_counts.php'].map(u => NOTIF_DEBUG ? (u + (u.includes('?') ? '&' : '?') + 'debug=1') : u);
    let refreshCtrl = null;

    async function fetchFirstOk(urls) {
      let lastErr;
      for (const url of urls) {
        try {
          const ctrl = new AbortController();
          refreshCtrl = ctrl;
          const r = await fetch(url, {
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json'
            },
            signal: ctrl.signal
          });
          if (!r.ok) {
            lastErr = new Error('HTTP ' + r.status + ' @ ' + url);
            continue;
          }
          const text = await r.text();
          try {
            return JSON.parse(text);
          } catch (e) {
            lastErr = new Error('JSON parse fail @ ' + url + ': ' + e);
            continue;
          }
        } catch (e) {
          lastErr = e;
        }
      }
      throw lastErr || new Error('No endpoints responded');
    }

    function renderMarquee(items) {
      marquee.innerHTML = '';
      if (!Array.isArray(items) || !items.length) {
        marquee.textContent = '✅ Brak nowych powiadomień.';
        return;
      }
      items.forEach(it => {
        let icon = '🔔',
          text = '';
        if (it && typeof it === 'object') {
          icon = it.icon || '🔔';
          text = String(it.text || '');
        } else {
          text = String(it);
        }
        const pill = document.createElement('span');
        pill.className = 'marq-pill';
        const ico = document.createElement('span');
        ico.className = 'ico';
        ico.textContent = icon;
        const txt = document.createElement('span');
        txt.textContent = text;
        pill.appendChild(ico);
        pill.appendChild(txt);
        marquee.appendChild(pill);
      });
    }

    function renderCounts(m) {
      const queue = (m?.cw_queue | 0),
        errs = (m?.cw_errors | 0);
      const badgeVal = queue + errs;
      badge.style.display = badgeVal > 0 ? 'inline-block' : 'none';
      if (badgeVal > 0) badge.textContent = String(badgeVal);
      bell.style.color = (errs > 0) ? 'crimson' : '';
    }
    async function refresh() {
      try {
        const notifsRaw = await fetchFirstOk(notifCandidates);
        const items = Array.isArray(notifsRaw) ? notifsRaw : (notifsRaw?.notifications || []);
        renderMarquee(items);
        try {
          const countsRaw = await fetchFirstOk(countsCandidates);
          if (countsRaw?.ok) renderCounts(countsRaw.metrics);
        } catch (_) {}
      } catch (_) {
        marquee.textContent = '⚠️ Błąd ładowania powiadomień.';
        badge.style.display = 'none';
      }
    }
    refresh();
    setInterval(() => {
      try {
        refreshCtrl?.abort();
      } catch (_) {}
      refresh();
    }, 30000);
  });
</script>