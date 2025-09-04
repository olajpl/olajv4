// /assets/js/shop.js
(function () {
  // ===== helpers =====
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const nf = new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function getCsrf() {
    return document.querySelector('meta[name="csrf"]')?.content || window.__SHOP__?.csrf || '';
  }
  function toast(msg) {
    const t = $('#toast'); if (!t) return;
    t.textContent = msg || '';
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 2500);
  }

  // ===== free shipping =====
  function updateFreeShip(info) {
    const bar = $('#freeShipBar'), fill = $('#freeShipFill'), txt = $('#freeShipText');
    if (!bar || !fill || !txt || !info) { if (bar) bar.classList.add('hidden'); return; }
    bar.classList.remove('hidden');
    const pct = Math.max(0, Math.min(100, +info.progress_pct || 0));
    fill.style.width = pct + '%';
    txt.textContent = info.missing_formatted ? `Brakuje ${info.missing_formatted} do darmowej dostawy` : 'Darmowa dostawa!';
  }
  window.updateFreeShip = updateFreeShip;

  // ===== mini cart =====
  function toggleMiniCart(open = true) {
    const el = $('#miniCart'); if (el) el.style.transform = open ? 'translateX(0)' : 'translateX(100%)';
  }
  window.toggleMiniCart = toggleMiniCart;

  function renderMiniCTA(count) {
    const cta = $('#miniCta'); if (!cta) return;
    const loggedIn = !!window.__SHOP__?.loggedIn;
    if ((+count || 0) > 0) {
      if (loggedIn) {
        cta.innerHTML = `
          <div class="mt-4">
            <form method="post" action="/cart/submit.php">
              <input type="hidden" name="csrf" value="${getCsrf()}">
              <button type="submit" class="w-full text-center py-3 rounded-lg text-white font-semibold" style="background: var(--theme-color);">Przejdź do checkout</button>
            </form>
          </div>`;
      } else {
        cta.innerHTML = `
          <div class="mt-4 grid gap-2 sm:grid-cols-2">
            <a href="/konto/recover.php?redirect=%2Fcheckout%2Findex.php" class="text-center py-3 rounded-lg text-white font-semibold" style="background: var(--theme-color);">🔑 Odzyskaj dostęp</a>
            <a href="/konto/register.php?redirect=%2Fcheckout%2Findex.php" class="text-center py-3 rounded-lg font-semibold border">✍️ Zarejestruj</a>
          </div>`;
      }
    } else {
      cta.innerHTML = '';
    }
  }

  function updateMiniCart(data) {
    const el = $('#miniCartBody'); if (!el) return;
    const items = Array.isArray(data?.items) ? data.items : [];
    el.innerHTML = items.map(it => `
      <div class="flex items-center gap-3 group" data-pid="${it.id}">
        <img src="${it.thumb}" alt="" class="w-12 h-12 object-cover rounded" loading="lazy">
        <div class="flex-1">
          <div class="font-medium line-clamp-1">${it.name}</div>
          <div class="text-xs text-gray-500">${it.price}</div>
          <div class="mt-1 flex items-center gap-2">
            <button class="px-2 py-1 border rounded" data-op="dec" aria-label="Zmniejsz">−</button>
            <input class="w-12 text-center border rounded py-1" type="number" min="0" value="${it.qty}" data-role="qty" aria-label="Ilość pozycji">
            <button class="px-2 py-1 border rounded" data-op="inc" aria-label="Zwiększ">+</button>
            <button class="ml-auto text-gray-400 hover:text-red-600" data-op="remove" title="Usuń" aria-label="Usuń">🗑</button>
          </div>
        </div>
        <div class="text-sm font-semibold min-w-[72px] text-right">${it.line_total}</div>
      </div>`).join('') || `<div class="text-gray-500">Koszyk pusty</div>`;

    const count = items.reduce((s, i) => s + (parseInt(i.qty) || 0), 0);
    const badge = $('#cart-count');
    if (badge) {
      if (count > 0) { badge.textContent = count; badge.classList.remove('hidden'); }
      else { badge.classList.add('hidden'); }
    }
    if (data.sum_formatted) { const s = $('#miniSum'); if (s) s.textContent = data.sum_formatted; }
    renderMiniCTA(count);
  }
  window.updateMiniCart = updateMiniCart;

  let miniBusy = false;
  function miniUpdate(productId, op, qty) {
    if (miniBusy) return; miniBusy = true;
    const params = new URLSearchParams();
    params.set('product_id', productId);
    params.set('op', op);
    if (op === 'set') params.set('quantity', (qty ?? 0));
    fetch('/cart/update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': getCsrf() },
      body: params.toString()
    }).then(r => r.json()).then(res => {
      miniBusy = false;
      if (res.status === 'ok') {
        if (res.mini) updateMiniCart(res.mini);
        if (res.free_shipping) updateFreeShip(res.free_shipping);
      } else {
        toast(res.message || '❌ Nie udało się zaktualizować koszyka');
      }
    }).catch(() => { miniBusy = false; toast('❌ Błąd sieci'); });
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-op]'); if (!btn) return;
    const row = btn.closest('[data-pid]'); if (!row) return;
    const pid = parseInt(row.getAttribute('data-pid'), 10);
    const op  = btn.getAttribute('data-op');
    if (['inc', 'dec'].includes(op)) {
  miniUpdate(pid, op);
} else if (op === 'remove') {
  removeFromCart(pid);
}

  });
  document.addEventListener('change', (e) => {
    const input = e.target.closest('input[data-role="qty"]'); if (!input) return;
    const row = input.closest('[data-pid]'); if (!row) return;
    const pid = parseInt(row.getAttribute('data-pid'), 10);
    const q   = Math.max(0, parseInt(input.value || '0', 10));
    miniUpdate(pid, 'set', q);
  });

  // ===== add to cart =====
  function animateFlyToCart(imgEl) {
    const cart = $('.cart-button'); if (!cart || !imgEl) return;
    const a = imgEl.getBoundingClientRect(), b = cart.getBoundingClientRect();
    const clone = imgEl.cloneNode(true);
    Object.assign(clone.style, { position: 'fixed', zIndex: 9999, top: a.top + 'px', left: a.left + 'px', width: a.width + 'px', transition: 'transform .8s ease, opacity .8s', pointerEvents: 'none' });
    document.body.appendChild(clone);
    requestAnimationFrame(() => { clone.style.transform = `translate(${b.left - a.left}px, ${b.top - a.top}px) scale(0.2)`; clone.style.opacity = '0'; });
    setTimeout(() => clone.remove(), 800);
  }

  function addToCart(productId, qty = 1) {
    fetch('/cart/add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': getCsrf() },
      body: 'product_id=' + encodeURIComponent(productId) + '&quantity=' + encodeURIComponent(qty)
    }).then(r => r.json()).then(res => {
      if (res.status === 'ok') {
        try { window.lottieCart?.goToAndPlay(0, true); } catch (e) {}
        if (res.mini) updateMiniCart(res.mini);
        if (res.free_shipping) updateFreeShip(res.free_shipping);
        toast(res.message || 'Dodano do koszyka');
        toggleMiniCart(true);
        if (window.__SHOP__?.soundOnAdd) new Audio('/sounds/add.mp3').play();
      } else {
        toast(res.message || '❌ Błąd podczas dodawania do koszyka');
      }
    }).catch(() => toast('❌ Błąd sieci'));
  }
  window.addToCart = addToCart;

  window.addToCartInline = function (productId, button) {
    const card = button.closest('[data-pid]');
    const img = card?.querySelector('img');
    const input = button.closest('.flex.items-stretch')?.querySelector('input[type="number"]');
    const qty = Math.max(1, parseInt(input?.value || '1', 10));
    if (img) animateFlyToCart(img);
    addToCart(productId, qty);
  };

  // ===== quantity step helper (grid) =====
  window.stepQty = function (btn, delta) {
    const input = btn.parentElement.querySelector('input[type="number"]');
    const cur = parseInt(input.value) || 1;
    input.value = Math.max(1, cur + delta);
  };

  // ===== product modal =====
  const MOD = {
    root:  null, img: null, title: null, price: null, meta: null, desc: null,
    qty:   null, addBtn: null, closeBtn: null,
    pid:   0,    currency: 'PLN'
  };

  function cacheModalNodes() {
    MOD.root    = $('#product-modal');
    MOD.img     = $('#pm-image');
    MOD.title   = $('#pm-title');
    MOD.price   = $('#pm-price');
    MOD.meta    = $('#pm-meta');
    MOD.desc    = $('#pm-desc');
    MOD.qty     = $('#pm-qty');
    MOD.addBtn  = $('#pm-add');
    MOD.closeBtn= $('#pm-close');
  }

  function showModal() { if (MOD.root) MOD.root.classList.remove('hidden'); }
  function hideModal() { if (MOD.root) MOD.root.classList.add('hidden'); MOD.pid = 0; }

  function bindModalEvents() {
    if (!MOD.root) return;
    MOD.root.addEventListener('click', (e) => { if (e.target === MOD.root) hideModal(); });
    MOD.closeBtn?.addEventListener('click', hideModal);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !MOD.root.classList.contains('hidden')) hideModal(); });
    $('#pm-inc')?.addEventListener('click', () => { MOD.qty.value = Math.max(1, (parseInt(MOD.qty.value || '1', 10) || 1) + 1); });
    $('#pm-dec')?.addEventListener('click', () => { MOD.qty.value = Math.max(1, (parseInt(MOD.qty.value || '1', 10) || 1) - 1); });
    MOD.addBtn?.addEventListener('click', () => {
      const q = Math.max(1, parseInt(MOD.qty.value || '1', 10));
      addToCart(MOD.pid, q);
      hideModal();
    });
  }

  function populateModalFallback(card) {
    const name   = card.dataset.name || 'Produkt';
    const price  = parseFloat(card.dataset.price || '0') || 0;
    const curr   = card.dataset.currency || (window.__SHOP__?.currency || 'PLN');
    const weight = card.dataset.weight || '';
    const stock  = parseFloat(card.dataset.stock || '0') || 0;
    const img    = card.dataset.img || 'https://via.placeholder.com/300x300?text=Produkt';

    MOD.currency = curr;
    if (MOD.title) MOD.title.textContent = name;
    if (MOD.price) MOD.price.textContent = `${nf.format(price)} ${curr}`;
    if (MOD.meta)  MOD.meta.textContent  = `${weight ? 'Waga: ' + weight + ' kg • ' : ''}${stock > 0 ? 'Dostępność: ✅' : 'Dostępność: ⛔'}`;
    if (MOD.img)   MOD.img.src = img;
    if (MOD.desc)  MOD.desc.textContent  = 'Ładuję opis…';
  }

  function populateModalFromApi(d) {
    if (MOD.title) MOD.title.textContent = d.name || 'Produkt';
    if (MOD.price) MOD.price.textContent = d.price_formatted || `${nf.format(d.price || 0)} ${d.currency || MOD.currency}`;
    const metaBits = [];
    if (typeof d.weight_kg === 'number') metaBits.push(`Waga: ${nf.format(d.weight_kg)} kg`);
    metaBits.push(`Dostępność: ${(+d.stock || 0) > 0 ? '✅' : '⛔'}`);
    if (MOD.meta) MOD.meta.textContent = metaBits.join(' • ');
    if (Array.isArray(d.images) && d.images[0] && MOD.img) MOD.img.src = d.images[0];
    if (MOD.desc) {
      const html = (d.description || '').toString();
      const isHtml = /<\/?[a-z][\s\S]*>/i.test(html);
      if (isHtml) {
        const t = document.createElement('template'); t.innerHTML = html; MOD.desc.replaceChildren(t.content);
      } else {
        MOD.desc.textContent = html || '—';
      }
    }
  }

  // wywoływane z gridu: <button onclick="openProductCardFromCard(this)">
  window.openProductCardFromCard = function (btn) {
    if (!MOD.root) cacheModalNodes();
    const card = btn.closest('[data-pid]'); if (!card) return;
    const pid = +card.dataset.pid; MOD.pid = pid;
    populateModalFallback(card);
    showModal();

    // dociąg opisu ze /api/products/info.php
    fetch('/api/products/info.php?id=' + encodeURIComponent(pid), { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : null)
      .then(j => {
        if (j?.ok && j.data) {
          MOD.currency = j.data.currency || MOD.currency;
          populateModalFromApi(j.data);
        } else {
          if (MOD.desc && !MOD.desc.textContent) MOD.desc.textContent = '—';
        }
      })
      .catch(() => { if (MOD.desc && !MOD.desc.textContent) MOD.desc.textContent = '—'; });

    // reset qty
    if (MOD.qty) MOD.qty.value = '1';
  };

  // ===== Lottie + ticker + live overlay =====
  function initLottie() {
    try {
      if (!window.lottie) return;
      const anim = lottie.loadAnimation({
        container: $('#lottie-cart'),
        renderer: 'svg', loop: false, autoplay: false,
        path: '/uploads/lottie/shopping_cart.json'
      });
      anim.addEventListener('DOMLoaded', () => { const f = $('#fallback-cart'); if (f) f.style.display = 'none'; });
      window.lottieCart = anim;
    } catch (_) { /* noop */ }
  }

  function initTickerDup() {
    const track = $('#tickerTrack'); if (!track) return;
    const cloneOnce = track.cloneNode(true);
    [...cloneOnce.children].forEach(el => track.appendChild(el.cloneNode(true)));
    if (track.scrollWidth < track.parentElement.offsetWidth * 2) {
      [...cloneOnce.children].forEach(el => track.appendChild(el.cloneNode(true)));
    }
  }

  function initLiveOverlay() {
    const liveId = window.__SHOP__?.liveId | 0; if (!liveId) return;
    const overlay = $('#live-overlay'); if (!overlay) return;
    const nameEl = $('#live-offer-name');
    const priceEl = $('#live-offer-price');
    const imgEl = $('#live-offer-img');
    const btn = $('#live-offer-add');

    let timer = null, backoffMs = 5000, abortCtrl = null;
    async function refreshOffer() {
      try {
        if (document.hidden) return;
        abortCtrl?.abort(); abortCtrl = new AbortController();
        const to = setTimeout(() => abortCtrl.abort(), 4000);
        const res = await fetch('/api/live/get_active_offer.php?live_id=' + encodeURIComponent(liveId), {
          cache: 'no-store', credentials: 'same-origin', signal: abortCtrl.signal
        });
        clearTimeout(to);
        if (!res.ok || !(res.headers.get('content-type') || '').includes('application/json')) throw new Error('Bad response');
        const data = await res.json();
        if (data && data.success && data.offer) {
          overlay.style.display = 'block';
          nameEl.textContent = data.offer.name || '';
          priceEl.textContent = data.offer.price_formatted || '';
          imgEl.src = data.offer.image_url || '/img/no-image.png';
          btn.onclick = () => addToCart(data.offer.id, 1);
          backoffMs = 5000;
        } else {
          overlay.style.display = 'none';
          backoffMs = Math.min(20000, backoffMs + 2000);
        }
      } catch (e) {
        backoffMs = Math.min(30000, backoffMs * 2);
      } finally {
        clearTimeout(timer);
        timer = setTimeout(refreshOffer, backoffMs);
      }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshOffer(); });
    refreshOffer();
  }

  // ===== init =====
  document.addEventListener('DOMContentLoaded', () => {
    // preload free shipping from SSR
    if (window.__SHOP__?.freeShip?.threshold > 0) {
      updateFreeShip({
        progress_pct: window.__SHOP__.freeShip.progress_pct || 0,
        missing_formatted: window.__SHOP__.freeShip.missing_formatted || null
      });
    }
    // CTA initial
    const initialCount = parseInt($('#cart-count')?.textContent || '0', 10) || 0;
    renderMiniCTA(initialCount);

    initLottie();
    initTickerDup();
    cacheModalNodes();
    bindModalEvents();
    initLiveOverlay();
  });
})();
function removeFromCart(productId) {
  if (!productId || isNaN(productId)) return;
  const params = new URLSearchParams();
  params.set('product_id', productId);
  const row = document.querySelector('#item-' + productId);
if (row) {
  row.style.transition = 'opacity .3s ease';
  row.style.opacity = '0.4';
}

  fetch('/cart/remove.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': getCsrf()
    },
    body: params.toString()
  }).then(r => r.json()).then(res => {
    if (res.status === 'ok') {
      if (res.mini) updateMiniCart(res.mini);
      if (res.free_shipping) updateFreeShip(res.free_shipping);
      toast(res.message || 'Usunięto z koszyka');
    } else {
      toast(res.message || '❌ Nie udało się usunąć produktu');
    }
  }).catch(() => {
    toast('❌ Błąd sieci');
  });
}
window.removeFromCart = removeFromCart;
