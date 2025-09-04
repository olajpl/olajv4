# PaymentEngine — Olaj.pl V4

## Zakres odpowiedzialności
- Rejestr „płatności” (`payments`) i ich stanów: draft → started → pending → paid / failed / cancelled / refunded
- Rejestr transakcji częściowych (`payment_transactions`): wpłata / zwrot / korekta (idempotencja: provider + provider_tx_id)
- Agregacja i rekalkulacja:
  - `order_groups.paid_status` ∈ {nieopłacona, częściowa, opłacona, nadpłata}
  - agregaty na poziomie ordera (suma grup) + `last_payment_at`

> **Nie** zmienia `order_status` (to domena OrderEngine/Checkout). PaymentEngine = kasa i tylko kasa.

## Szybkie API
```php
use Engine\Payment\PaymentEngine;

$pe = new PaymentEngine($pdo, $owner_id);

$paymentId = $pe->createDraftPayment($orderId, $groupId, $paymentMethodId, 'p24', 'online', $amount);
$pe->markStarted($paymentId, $providerPaymentId);
$pe->markPending($paymentId);
$pe->markPaid($paymentId, $capturedAmount, date('Y-m-d H:i:s'));

$txId = $pe->addTransaction([
  'order_group_id'   => 123,
  'transaction_type' => 'wpłata',
  'status'           => 'zaksięgowana',
  'amount'           => 37.00,
  'currency'         => 'PLN',
  'provider'         => 'p24',
  'provider_tx_id'   => 'P24-abc-123', // idempotencja
  'method'           => 'blik',
  'external_source'  => 'psp',
  'booked_at'        => date('Y-m-d H:i:s'),
]);

$agg = $pe->recalcGroupPaidStatus(123);


flowchart LR
  PSP[PSP/Webhook] -->|provider, provider_tx_id| PE{{PaymentEngine}}
  PE -->|addTransaction()| PT[(payment_transactions)]
  PE -->|recalcGroupPaidStatus()| OG[order_groups]
  PE -->|recalcOrderPaidAggregates()| O[orders]
  OG -->|paid_status| Panel[Panel Orders]
  PE -->|logg()| Logger[(olaj_v4_logger)]



# Notatki integracyjne (panel)

- `/admin/orders/index.php`
  - Jeśli istnieje `payment_transactions` → używaj `v_order_payments` (paid_amount_pln, last_payment_at, is_paid).
  - Jeśli jeszcze nie ma → fallback na `payments` (tak już zaszyliśmy w patchach).
- Przy filtrze „opłacone/nieopłacone” porównuj **sumę transakcji** z sumą pozycji (tolerancja 0.01).

# Checklist wdrożeniowy

- [ ] Wykonaj SQL z pliku `sql/2025-08-23_payment_engine.sql` na produkcji.
- [ ] Skopiuj plik `engine/Payment/PaymentEngine.php` do repo.
- [ ] Upewnij się, że `/admin/orders/index.php` ma logikę transakcyjną (paid_amount_pln/last_payment_at).
- [ ] Test: dodaj ręcznie transakcję (wpłata 1,00 PLN) → zobacz zmianę `paid_status` grupy.
- [ ] Test: dodaj zwrot częściowy → `paid_status` powinien przejść z „opłacona” → „częściowa”.
- [ ] Test: idempotencja webhooków (dwukrotne wywołanie z tym samym `provider_tx_id` ≠ duplikaty).
- [ ] (Opcjonalnie) Podepnij CW: event „dopłata otrzymana” → podziękowanie do klienta.

# Git – z przyzwyczajenia 😉

```bash
git add engine/Payment/PaymentEngine.php \
        sql/2025-08-23_payment_engine.sql \
        README_DATABASE_CHANGELOG.md \
        engine/Payment/README.md
git commit -m "feat(payment): PaymentEngine + partial payments via payment_transactions, views & panel integration"
git push
