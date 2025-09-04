<?php
// layout/layout_footer.php
$elapsed = defined('APP_START') ? (microtime(true) - APP_START) : 0;
$memUsage = memory_get_usage(true) / 1024 / 1024;
$memPeak  = memory_get_peak_usage(true) / 1024 / 1024;
?>
</main>
</div> <!-- .flex -->

<!-- Overlay dla mobile -->
<div id="sidebarOverlay" aria-hidden="true"></div>

<footer id="bottombar" class="fixed bottom-0 left-0 right-0 flex justify-between text-sm text-gray-500 py-3 px-6 border-t bg-white z-30">
  <div>Olaj.pl © 2025 <span class="ml-4">System V4.0.9</span></div>
  <div class="text-right space-x-4">
    <div id="system-status" class="text-xs text-stone-500">
      Baza: <span id="status-logger" class="status-dot"></span>
      CW: <span id="status-webhook" class="status-dot"></span>
    </div>

    <script>
      async function refreshStatus() {
        try {
          const res = await fetch('/../admin/health.php', {
            credentials: 'same-origin'
          });
          const data = await res.json();
          const set = (id, ok) => {
            const el = document.getElementById(id);
            el.className = 'status-dot ' + (ok ? 'status-ok' : 'status-fail');
          };
          set('status-logger', data.logger_ok);
          set('status-webhook', data.webhook_ok);
        } catch (e) {
          // jak health.php padnie → oba na czerwono
          ['status-logger', 'status-webhook'].forEach(id => {
            document.getElementById(id).className = 'status-dot status-fail';
          });
        }
      }
      refreshStatus();
      setInterval(refreshStatus, 30000);
    </script>

    <span>⏱ <?= number_format($elapsed, 3) ?> s</span>
    <span>💾 <?= number_format($memUsage, 2) ?> MB (peak <?= number_format($memPeak, 2) ?> MB)</span>
    <span>🔍 <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?></span>
  </div>
</footer>

<!-- ❗ Kluczowa zmiana: desktop = sidebar w flow z animowanym width; mobile = off-canvas -->
<style>
  :root {
    --sidebar-w: 220px;
    --sidebar-w-collapsed: 64px;
  }

  /* MOBILE (<= md) – off-canvas */
  @media (max-width: 767.98px) {

    /* Sidebar jako szuflada */
    nav.sidebar {
      position: fixed !important;
      top: var(--header-h, 60px);
      bottom: var(--footer-h, 56px);
      left: 0;
      width: 260px;
      transform: translateX(-102%);
      opacity: 1;
      transition: transform .25s ease;
      z-index: 50;
      box-shadow: 0 10px 25px rgba(0, 0, 0, .25);
      display: block !important;
      /* nadpisz hidden md:block */
    }

    body.sidebar-open nav.sidebar {
      transform: translateX(0);
    }

    /* Overlay */
    #sidebarOverlay {
      position: fixed;
      left: 0;
      right: 0;
      top: var(--header-h, 60px);
      bottom: var(--footer-h, 56px);
      background: rgba(0, 0, 0, .35);
      z-index: 40;
      display: none;
    }

    body.sidebar-open #sidebarOverlay {
      display: block;
    }

    /* Gdy otwarta szuflada – zablokuj interakcje pod spodem */
    body.sidebar-open main#content {
      pointer-events: none;
    }
  }

  /* DESKTOP (>= md) – sidebar w flow z animowanym width */
  @media (min-width: 768px) {
    nav.sidebar {
      position: relative !important;
      top: auto;
      bottom: auto;
      left: auto;
      transform: none !important;
      box-shadow: none !important;
      width: var(--sidebar-w);
      transition: width .25s ease;
      z-index: 1;
      /* ponad tłem contentu, ale pod top/footerem */
    }

    body.sidebar-collapsed nav.sidebar {
      width: var(--sidebar-w-collapsed);
    }

    /* Content pływa automatycznie dzięki flex, ale dorzucamy płynność reflow */
    main#content {
      transition: width .25s ease, margin .25s ease, padding .25s ease;
    }

    /* Ukryj overlay na desktopie (niepotrzebny) */
    #sidebarOverlay {
      display: none !important;
    }

    /* Na desktopie nigdy nie używamy .sidebar-open */
    body.sidebar-open nav.sidebar {
      transform: none !important;
    }
  }
</style>

<script>
  // Sticky wysokości top/bottom → CSS var
  (function() {
    function applyBarsHeights() {
      const top = document.getElementById('topbar');
      const bot = document.getElementById('bottombar');
      if (top) document.documentElement.style.setProperty('--header-h', top.offsetHeight + 'px');
      if (bot) document.documentElement.style.setProperty('--footer-h', bot.offsetHeight + 'px');
    }
    window.addEventListener('load', applyBarsHeights);
    window.addEventListener('resize', applyBarsHeights);
    if ('ResizeObserver' in window) {
      const ro = new ResizeObserver(applyBarsHeights);
      const top = document.getElementById('topbar');
      const bot = document.getElementById('bottombar');
      if (top) ro.observe(top);
      if (bot) ro.observe(bot);
    }
    applyBarsHeights();
  })();
</script>

<script>
  (function() {
    const body = document.body;
    const mqlDesktop = window.matchMedia('(min-width: 768px)');

    // --- Desktop collapse (persist) ---
    const LS_KEY = 'olaj.sidebar.collapsed';
    const getCollapsed = () => {
      try {
        return localStorage.getItem(LS_KEY) === '1';
      } catch (e) {
        return false;
      }
    };
    const setCollapsed = (v) => {
      try {
        localStorage.setItem(LS_KEY, v ? '1' : '0');
      } catch (e) {}
    };
    const applyCollapsed = (state) => body.classList.toggle('sidebar-collapsed', !!state);

    applyCollapsed(getCollapsed());

    // --- Mobile open/close ---
    function toggleMobile(forceOpen) {
      const open = (forceOpen === undefined) ? !body.classList.contains('sidebar-open') : !!forceOpen;
      body.classList.toggle('sidebar-open', open);
      const main = document.getElementById('content');
      if (main) main.toggleAttribute('aria-hidden', open);
    }

    // Hamburger z topbara wysyła event 'toggle-sidebar'
    window.addEventListener('toggle-sidebar', () => {
      if (mqlDesktop.matches) {
        const next = !getCollapsed();
        applyCollapsed(next);
        setCollapsed(next);
      } else {
        toggleMobile();
      }
    });

    // Skrót klawiaturowy: S (desktop collapse)
    window.addEventListener('keydown', (e) => {
      if (e.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
      if ((e.key === 's' || e.key === 'S') && mqlDesktop.matches) {
        const next = !getCollapsed();
        applyCollapsed(next);
        setCollapsed(next);
      }
      if (e.key === 'Escape' && !mqlDesktop.matches && body.classList.contains('sidebar-open')) {
        toggleMobile(false);
      }
    });

    // Klik w overlay zamyka drawer
    document.getElementById('sidebarOverlay')?.addEventListener('click', () => toggleMobile(false));

    // Zmiana viewportu: resetuj stan mobilny
    mqlDesktop.addEventListener?.('change', (e) => {
      if (e.matches) {
        body.classList.remove('sidebar-open');
        applyCollapsed(getCollapsed());
      } else {
        applyCollapsed(false); // na mobile ignorujemy desktopowe „collapsed”
      }
    });
  })();
</script>
</body>

</html>