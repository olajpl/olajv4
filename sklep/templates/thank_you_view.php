<!DOCTYPE html>
<html lang="pl">

<head>
  <meta charset="UTF-8" />
  <title>Dziękujemy za zamówienie</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .glass {
      backdrop-filter: blur(8px);
      background: rgba(255, 255, 255, .78);
    }

    .brand-grad {
      background: linear-gradient(135deg, #f43f5e 0%, #ec4899 45%, #8b5cf6 100%);
    }

    .brand-text {
      background: linear-gradient(135deg, #f43f5e 0%, #ec4899 60%, #8b5cf6 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .shine {
      position: relative;
      overflow: hidden;
    }

    .shine:after {
      content: "";
      position: absolute;
      top: -50%;
      left: -30%;
      width: 60%;
      height: 200%;
      transform: rotate(25deg);
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .35), transparent);
      animation: shine 2.6s infinite;
    }

    @keyframes shine {
      0% {
        left: -30%
      }

      100% {
        left: 130%
      }
    }
  </style>
</head>

<body class="min-h-screen bg-gradient-to-br from-pink-50 via-rose-50 to-violet-50">
  <header class="brand-grad text-white shadow-lg">
    <div class="max-w-5xl mx-auto px-3 sm:px-4 py-4 sm:py-5 flex items-center justify-between">
      <h1 class="text-xl sm:text-3xl font-extrabold tracking-tight shine">✅ Dziękujemy za zamówienie!</h1>
      <a href="/" class="hidden sm:inline-block px-4 py-2 rounded-lg bg-white/10 hover:bg-white/20">Wróć do sklepu</a>
    </div>
  </header>

  <main class="max-w-5xl mx-auto px-3 sm:px-4 py-5 sm:py-8 grid lg:grid-cols-3 gap-4 sm:gap-5">
    <?php if ($splitFlash): ?>
      <div class="max-w-5xl mx-auto mb-3 sm:mb-4">
        <div class="px-3 sm:px-4 py-3 rounded-xl border bg-red-50 border-red-200 text-red-800">
          <div class="font-bold mb-1">⚠️ Twoja paczka przekroczyła limit wagowy.</div>
          <div class="text-sm">
            Zamówienie zostało <strong>podzielone na dwie paczki</strong>.
            <a class="underline font-semibold" href="<?= e((string)$splitFlash['summary_link']) ?>">Przejdź do nowego podsumowania</a>
            <span class="text-[11px] opacity-80 ml-1">(<a class="underline" href="<?= e((string)$splitFlash['checkout_link']) ?>">albo do checkoutu</a>)</span>.
          </div>
        </div>
      </div>
    <?php endif; ?>

    <section class="lg:col-span-2 space-y-4 sm:space-y-5">
      <!-- PACZKA -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-slate-500">Zamówienie #<?= (int)$orderId ?> • Paczka #<?= (int)$groupId ?></div>
              <div class="font-black text-lg sm:text-xl brand-text">Twoja paczka</div>
            </div>
            <div class="text-sm"><?= badgePaidStatus($groupPaidStatus) ?></div>
          </div>
        </div>

        <div class="p-4 sm:p-6 divide-y">
          <?php if (empty($items)): ?>
            <div class="text-slate-500 py-8 text-center">Brak pozycji w tej paczce.</div>
            <?php else: foreach ($items as $it):
              $qty = (float)$it['quantity'];
              $u = (float)$it['unit_price'];
              $line = $qty * $u;
              $src = (string)$it['src'];
              $wkg = (float)$it['weight'];
              $wline = $wkg * $qty;
            ?>
              <div class="py-3 flex items-start gap-3">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <div class="font-semibold truncate"><?= e($it['product_name'] ?? 'Produkt') ?></div>
                    <div class="flex items-center gap-1 flex-wrap">
                      <?= badgeSource($src) ?> <?= badgePaidStatus($groupPaidStatus) ?>
                    </div>
                  </div>
                  <div class="text-sm text-slate-500">
                    Ilość: <?= $qty ?> × <?= fmt_price($u) ?>
                    <?php if ($wkg > 0): ?> • Waga: <?= fmt_weight($wkg) ?> × <?= $qty ?> = <strong><?= fmt_weight($wline) ?></strong><?php endif; ?>
                  </div>
                </div>
                <div class="font-bold"><?= fmt_price($line) ?></div>
              </div>
          <?php endforeach;
          endif; ?>
        </div>

        <div class="p-4 sm:p-6 bg-white/60 rounded-b-2xl">
          <div class="grid grid-cols-2 gap-3">
            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Produkty</div>
              <div class="text-lg sm:text-xl font-extrabold"><?= fmt_price($total_products) ?></div>
            </div>
            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Dostawa (ta paczka)</div>
              <div class="text-lg sm:text-xl font-extrabold"><?= fmt_price($shipping_cost_group) ?></div>
              <?php if ($current_shipping_id > 0): ?>
                <div class="text-[11px] text-slate-500 mt-1">
                  Waga: <strong><?= fmt_weight($groupWeight) ?></strong>
                  • Paczek w tej PGZ: <strong><?= (int)$group_packages ?></strong>
                  <?= $limitLabel ? '(' . htmlspecialchars($limitLabel) . ')' : '' ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="text-sm sm:text-base mb-2">
              <div>Dostawa łącznie (zamówienie): <strong><?= fmt_price((float)$orderShipping['total_cost']) ?></strong></div>
              <div class="text-xs text-slate-600">
                Waga zamówienia: <?= number_format((float)$orderShipping['total_kg'], 2, ',', ' ') ?> kg
                • Limit informacyjny: <?= number_format((float)$orderShipping['limit_kg'], 2, ',', ' ') ?> kg
                <?php if (!empty($orderShipping['rules_suspended'])): ?>
                  • <span class="text-rose-600 font-semibold">reguły wagowe chwilowo wyłączone</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="glass rounded-xl p-3 sm:p-4 border border-white/70">
              <div class="text-slate-500 text-xs sm:text-sm">Suma tej paczki</div>
              <div class="text-base sm:text-lg font-black"><?= fmt_price($total_sum_group) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- LISTA PGZ -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <button type="button" onclick="toggleAcc('acc-pgz')" class="w-full text-left p-4 sm:p-6 flex items-center justify-between">
          <div>
            <div class="text-xs sm:text-sm text-slate-500">Zamówienie #<?= (int)$orderId ?></div>
            <div class="font-black text-lg">📦 Wszystkie paczki (PGZ)</div>
          </div>
          <span id="acc-pgz-icon" class="text-slate-500">▼</span>
        </button>
        <div id="acc-pgz" class="hidden px-4 sm:px-6 pb-4 sm:pb-6">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-slate-500">
                <tr>
                  <th class="text-left py-2">Paczka</th>
                  <th class="text-left py-2">Status opłaty</th>
                  <th class="text-right py-2">Pozycji</th>
                  <th class="text-right py-2">Suma</th>
                  <th class="text-right py-2">Akcja</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <?php foreach ($all_groups as $g):
                  $label = !empty($g['group_number']) ? ('#' . $g['group_number']) : ('PGZ ' . $g['id']);
                  $isCurrent = ((int)$g['id'] === $groupId);
                  $cnt = (int)($g['product_count'] ?? 0);
                  $sum = (float)($g['sum_total'] ?? 0.0);
                  $ps  = (string)($g['paid_status'] ?? 'nieopłacona');
                ?>
                  <tr>
                    <td class="py-2">
                      <div class="font-semibold"><?= e($label) ?></div>
                      <div class="text-[11px] text-slate-500"><?= e(date('Y-m-d H:i', strtotime($g['created_at']))) ?></div>
                    </td>
                    <td class="py-2"><?= badgePaidStatus($ps) ?></td>
                    <td class="py-2 text-right"><?= $cnt ?></td>
                    <td class="py-2 text-right font-semibold"><?= fmt_price($sum) ?></td>
                    <td class="py-2 text-right">
                      <?php if ($isCurrent): ?>
                        <span class="text-[11px] text-emerald-700">[ta paczka]</span>
                      <?php else: ?>
                        <!-- ✅ ujednolicone na ?token= -->
                        <a class="px-3 py-1 rounded-lg border hover:bg-white/70" href="?token=<?= urlencode((string)$g['checkout_token']) ?>">Pokaż</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($all_groups)): ?>
                  <tr>
                    <td colspan="5" class="py-4 text-center text-slate-500">Brak innych paczek.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ZMIANA DOSTAWY / ADRESU (po checkboxie) -->
      <?php if ($can_edit_delivery): ?>
        <div class="glass rounded-2xl shadow-xl border border-white/60">
          <div class="p-4 sm:p-6 border-b">
            <div class="font-black text-lg">✏️ Zmień metodę dostawy / adres</div>
            <?php if (!empty($_SESSION['delivery_error'])): ?>
              <div class="mt-3 p-3 rounded-lg bg-red-100 text-red-800 border border-red-200 text-sm">
                <?= e((string)$_SESSION['delivery_error']) ?>
              </div>
              <?php unset($_SESSION['delivery_error']); ?>
            <?php endif; ?>

            <!-- ✅ przełącznik widoczności formularza -->
            <label class="mt-3 inline-flex items-center gap-2 text-sm">
              <input type="checkbox" id="toggleDeliveryForm" class="h-4 w-4">
              <span>Pokaż formularz zmiany dostawy/adresu</span>
            </label>
          </div>

          <!-- ✅ wrapper ukrywający formularz + disable pól, gdy schowany -->
          <div id="deliveryFormWrap" class="p-4 sm:p-6 hidden">
            <form method="post" class="grid gap-3" id="deliveryForm">
              <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
              <input type="hidden" name="action" value="update_delivery">

              <label class="block">
                <span class="text-sm text-slate-600">Metoda dostawy</span>
                <select name="shipping_method_id" class="w-full mt-1 border rounded-lg p-2 bg-white" required>
                  <option value="">— wybierz —</option>
                  <?php foreach ($methods as $m):
                    $gPrev = calcShippingCostDetailed($pdo, $ownerId, $groupId, (int)$m['id'])['cost'];
                    $oPrev = calcOrderShippingCost($pdo, $ownerId, $orderId, (int)$m['id'])['cost'];
                  ?>
                    <option value="<?= (int)$m['id'] ?>" <?= ((int)$m['id'] === (int)$current_shipping_id) ? 'selected' : '' ?>>
                      <?= e($m['name']) ?> (ta paczka: <?= fmt_price($gPrev) ?> • całość: <?= fmt_price($oPrev) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($shippingName): ?>
                  <div class="mt-1 text-xs text-slate-500">Aktualnie: <strong>Metoda: <?= e($shippingName) ?></strong></div>
                <?php endif; ?>
                <div class="mt-1 text-[11px] text-slate-500">
                  Waga tej paczki: <?= fmt_weight($groupWeight) ?>
                </div>
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block"><span class="text-sm text-slate-600">Imię i nazwisko</span>
                  <input name="full_name" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['full_name'] ?? '')) ?>" required>
                </label>
                <label class="block"><span class="text-sm text-slate-600">Telefon</span>
                  <input name="phone" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['phone'] ?? '')) ?>" required>
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">E-mail</span>
                <input name="email" type="email" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['email'] ?? '')) ?>">
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="block sm:col-span-2"><span class="text-sm text-slate-600">Ulica</span>
                  <input name="street" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['street'] ?? '')) ?>">
                </label>
                <label class="block"><span class="text-sm text-slate-600">Kod</span>
                  <input name="postcode" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['postcode'] ?? '')) ?>">
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">Miasto</span>
                <input name="city" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['city'] ?? '')) ?>">
              </label>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block"><span class="text-sm text-slate-600">Kod paczkomatu (opcjonalnie)</span>
                  <input name="locker_code" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['locker_code'] ?? '')) ?>">
                </label>
                <label class="block"><span class="text-sm text-slate-600">Opis paczkomatu (opcjonalnie)</span>
                  <input name="locker_desc" class="w-full border rounded-lg p-2 mt-1" value="<?= e((string)($address['locker_desc'] ?? '')) ?>">
                </label>
              </div>

              <label class="block"><span class="text-sm text-slate-600">Uwagi</span>
                <textarea name="note" rows="2" class="w-full border rounded-lg p-2 mt-1"><?= e((string)($address['note'] ?? '')) ?></textarea>
              </label>

              <div class="pt-2">
                <button class="px-4 py-2 rounded-xl bg-pink-600 hover:bg-pink-700 text-white font-semibold shadow">Zapisz zmiany</button>
              </div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <!-- ZAMKNIĘCIE PACZKI -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="font-black text-lg">📦 Zamknij paczkę</div>
          <div class="text-sm text-slate-600 mt-1">Po zamknięciu paczki nie będzie można jej ponownie otworzyć ani dodawać produktów.</div>
        </div>
        <div class="p-4 sm:p-6">
          <form method="post" action="/checkout/send_package.php" id="closePkgForm" class="grid gap-3">
            <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label class="flex items-start gap-2">
              <input id="closeConfirm" type="checkbox" name="confirm_lock" value="1" class="mt-1">
              <span class="text-sm">Rozumiem, że <strong>zamknięta paczka</strong> nie może zostać ponownie otwarta i <strong>nie można</strong> do niej dołączać nowych produktów.</span>
            </label>
            <button id="closeBtn" type="submit" disabled
              class="px-4 py-2 rounded-xl bg-slate-300 text-white font-semibold shadow disabled:opacity-50 disabled:cursor-not-allowed">
              Zamknij paczkę
            </button>
            <div class="text-xs text-slate-500">Wyśle żądanie do <code>/checkout/send_package.php</code> (status „do wysyłki”).</div>
          </form>
        </div>
      </div>
    </section>

    <!-- PRAWA kolumna -->
    <aside class="lg:col-span-1 space-y-4 sm:space-y-5">
      <!-- PŁATNOŚĆ -->
      <div class="glass rounded-2xl shadow-xl border border-white/60">
        <div class="p-4 sm:p-6 border-b">
          <div class="font-black text-lg">💳 Płatność</div>
        </div>
        <div class="p-4 sm:p-6 space-y-3">
          <?php if ($payMethod): ?>
            <div class="text-sm text-slate-700 font-semibold">
              Metoda: <span class="brand-text font-bold"><?= e((string)$payMethod['name']) ?></span>
            </div>

            <?php if (($payMethod['type'] ?? '') === 'przelew'): ?>
              <?php if (!empty($payMethod['bank_account_name'])): ?>
                <div class="flex items-center justify-between text-sm">
                  <span><strong>Odbiorca:</strong> <?= e($payMethod['bank_account_name']) ?></span>
                  <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($payMethod['bank_account_name']) ?>">Kopiuj</button>
                </div>
              <?php endif; ?>
              <?php if (!empty($payMethod['bank_account_number'])): ?>
                <div class="flex items-center justify-between text-sm">
                  <span><strong>Konto:</strong> <?= e(fmt_account($payMethod['bank_account_number'])) ?></span>
                  <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($payMethod['bank_account_number']) ?>">Kopiuj</button>
                </div>
              <?php endif; ?>
              <?php $transferTitle = "nr zamówienia: {$orderId} - pgz: {$groupId}"; ?>
              <div class="flex items-center justify-between text-sm">
                <span><strong>Tytuł:</strong> <?= e($transferTitle) ?></span>
                <button type="button" class="copy-btn text-indigo-600 underline" data-copy="<?= e($transferTitle) ?>">Kopiuj</button>
              </div>
              <?php if (!empty($payMethod['bank_description'])): ?>
                <div class="text-sm text-slate-600"><?= nl2br(e($payMethod['bank_description'])) ?></div>
              <?php endif; ?>

            <?php elseif (($payMethod['type'] ?? '') === 'blik'): ?>
              <div class="rounded-xl p-4 brand-grad text-white">
                <div class="text-sm opacity-90">Płatność BLIK</div>
                <div class="text-2xl sm:text-3xl font-extrabold tracking-wide mt-1">
                  <?php if (!empty($payMethod['blik_phone'])): ?>
                    <a class="underline decoration-white/40 hover:decoration-white" href="tel:<?= e($payMethod['blik_phone']) ?>"><?= e($payMethod['blik_phone']) ?></a>
                    <?php else: ?>— brak numeru telefonu BLIK —<?php endif; ?>
                </div>
                <?php if (!empty($payMethod['bank_description'])): ?>
                  <div class="text-xs sm:text-sm mt-2 opacity-95"><?= nl2br(e($payMethod['bank_description'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($payMethod['blik_phone'])): ?>
                  <button type="button" class="mt-3 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 copy-btn" data-copy="<?= e($payMethod['blik_phone']) ?>">Skopiuj numer</button>
                <?php endif; ?>
              </div>

            <?php elseif (($payMethod['type'] ?? '') === 'pobranie'): ?>
              <div class="text-sm text-slate-600">Płatność przy odbiorze (pobranie).</div>

            <?php elseif (($payMethod['type'] ?? '') === 'gotówka'): ?>
              <div class="text-sm text-slate-600">Płatność gotówką przy odbiorze.</div>

            <?php elseif (($payMethod['type'] ?? '') === 'online'): ?>
              <div class="text-sm text-slate-600">Płatność online — znajdziesz ją w zakładce płatności zamówienia (jeśli została zainicjowana).</div>

            <?php else: ?>
              <div class="text-sm text-slate-600">Szczegóły płatności pojawią się w trakcie finalizacji lub nie są wymagane dla tej metody.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-sm text-slate-600">Brak przypiętej metody płatności do tej paczki.</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- SKRÓT (ta paczka + łączna dostawa) -->
      <div class="glass rounded-2xl shadow-xl border border-white/60 p-4 sm:p-6">
        <div class="text-slate-500 text-sm">Podsumowanie</div>
        <div class="mt-2 text-3xl font-black brand-text"><?= fmt_price($total_sum_group) ?></div>
        <div class="mt-1 text-xs text-slate-500">
          Produkty: <?= fmt_price($total_products) ?> • Dostawa (ta paczka): <?= fmt_price($shipping_cost_group) ?> • Waga: <?= fmt_weight($groupWeight) ?>
        </div>
        <div class="text-sm sm:text-base mb-2">
          <div>Dostawa łącznie (zamówienie): <strong><?= fmt_price((float)$orderShipping['total_cost']) ?></strong></div>
          <div class="text-xs text-slate-600">
            Łączna liczba paczek (parceli): <strong><?= (int)$orderShipping['parcel_count'] ?></strong>
            • Waga zamówienia: <?= number_format((float)$orderShipping['total_kg'], 2, ',', ' ') ?> kg
            • Limit paczki: <?= number_format((float)$orderShipping['limit_kg'], 2, ',', ' ') ?> kg
          </div>
        </div>

        <a href="/" class="inline-block w-full text-center px-4 py-2 rounded-xl border hover:bg-white/70">Wróć do sklepu</a>
      </div>
    </aside>
  </main>
  <?php
  // skonsumuj wpisy split_notice związane z tym orderem (żeby baner nie wracał)
  if ($splitFlash) {
    $_SESSION['split_notice'] = array_values(array_filter($_SESSION['split_notice'], function ($sn) use ($orderId) {
      return (int)($sn['for_order_id'] ?? 0) !== (int)$orderId;
    }));
  }
  ?>

  <script>
    function toggleAcc(id) {
      const el = document.getElementById(id);
      const ic = document.getElementById(id + '-icon');
      if (!el) return;
      el.classList.toggle('hidden');
      if (ic) ic.textContent = el.classList.contains('hidden') ? '▼' : '▲';
    }
    document.addEventListener('click', (e) => {
      const b = e.target.closest('.copy-btn');
      if (!b) return;
      const v = b.getAttribute('data-copy') || '';
      navigator.clipboard.writeText(v).then(() => {
        const old = b.textContent;
        b.textContent = 'Skopiowano!';
        setTimeout(() => b.textContent = old, 1200);
      });
    });
    const chk = document.getElementById('closeConfirm');
    const btn = document.getElementById('closeBtn');
    if (chk && btn) chk.addEventListener('change', () => btn.disabled = !chk.checked);

    // ✅ Sterowanie formularzem zmiany dostawy/adresu
    (function() {
      const toggle = document.getElementById('toggleDeliveryForm');
      const wrap = document.getElementById('deliveryFormWrap');
      const form = document.getElementById('deliveryForm');
      if (!toggle || !wrap || !form) return;

      function setDisabled(disabled) {
        const fields = form.querySelectorAll('input, select, textarea, button');
        fields.forEach(el => {
          if (el.name === 'csrf' || el.name === 'action') return;
          el.disabled = !!disabled;
        });
      }

      function applyState(show) {
        wrap.classList.toggle('hidden', !show);
        setDisabled(!show);
        try {
          localStorage.setItem('olaj_thankyou_delivery_form', show ? '1' : '0');
        } catch (e) {}
      }
      let saved = '0';
      try {
        saved = localStorage.getItem('olaj_thankyou_delivery_form') || '0';
      } catch (e) {}
      const initial = saved === '1';
      toggle.checked = initial;
      applyState(initial);
      toggle.addEventListener('change', () => applyState(toggle.checked));
    })();
  </script>
</body>

</html>
