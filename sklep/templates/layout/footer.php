  <div id="toast" class="fixed bottom-4 right-4 bg-gray-900 text-white text-sm px-4 py-2 rounded shadow hidden" role="status" aria-live="polite"></div>

  <script src="https://cdn.jsdelivr.net/npm/lottie-web@5.12.0/build/player/lottie.min.js"></script>
  <script>
      window.__SHOP__ = {
          csrf: <?= json_encode($PAGE['csrf']) ?>,
          currency: <?= json_encode($PAGE['settings']['currency'] ?? 'PLN') ?>,
          theme: <?= json_encode($PAGE['settings']['theme_color'] ?? '#ec4899') ?>,
          freeShip: <?= json_encode($FREE_SHIP, JSON_UNESCAPED_UNICODE) ?>,
          loggedIn: <?= $isLoggedIn ? 'true' : 'false' ?>,
          liveId: <?= (int)$active_live_id ?>,
          soundOnAdd: <?= (int)($settings['sound_on_add_to_cart'] ?? 0) ?>,
          themeName: <?= json_encode($PAGE['theme']) ?>,
          cdn: <?= json_encode($cdn) ?>,
      };
  </script>
  <script src="/assets/js/shop.js"></script>
  </body>

  </html>