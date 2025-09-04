🚀 README_ENGINE_REFAKTOR.md
🎯 Cel

Ujednolicenie i wzmocnienie logiki biznesowej systemu Olaj.pl V4 poprzez refaktoryzację silników (engine/) według poniższych zasad:

Każdy moduł logiczny (orders, stock, parser, live, messaging, payments…) posiada własny silnik

Silniki są niezależne, typizowane (strict_types=1), zgodne z PSR

Zintegrowane z ENUMami (engine/Enum)

Obsługują błędy (try/catch + logg), walidację i własną logikę transakcyjną

Gotowe do współpracy z LogEngine, Cw, ViewRenderer

📁 Silniki obecne w engine/
Katalog Plik Status refaktoryzacji Uwagi
Orders/ OrderEngine.php ✅ Pełny, typowany ENUM (OrderStatus, OrderItemSource, OrderItemChannel, Column)
ClientEngine.php 🟡 Podstawowy, działa Ma funkcję getClient() owner-safe, do rozbudowy
ProductEngine.php 🟡 Refaktoryzowany Stock, AI, tagi – ale jeszcze bez pełnej integracji enum
ViewRenderer.php ⚠️ Częściowo przepisany Renderuje status/płatność UI (do dokończenia pod ENUM)
Stock/ StockEngine.php 🟡 Nowy, z lockami Obsługuje rezerwacje, stock movements
Parser/ ParserEngine.php ✅ Gotowy Router do handlerów
Parser/Handlers/ DajHandler.php ✅ Gotowy Obsługuje komendę „daj” → CW, OrderEngine
Live/ LiveEngine.php ⚠️ Legacy + część z ENUM Częściowo zaktualizowany – wymaga dalszej migracji
CentralMessaging/ Cw.php ✅ Gotowy dispatch / send / enqueue
CwHelper.php ✅ Gotowy sendAutoReplyCheckoutWithToken, mapPlatformToSource itd.
CwTemplateResolver.php ✅ Gotowy obsługuje placeholdery
Channels/Messenger.php ✅ Gotowy bez SDK, czyste CURL
Channels/Email.php 🟡 Szkielet brak SMTP jeszcze
Log/ LogEngine.php ✅ Kompletny, mocny logg() centralny logger → logs
Shipping/ ShippingEngine.php 🟡 Szkielet brak jeszcze integracji z wysyłkami
Payments/ PaymentEngine.php 🟡 Draft tylko createDraft()
Webhook/ WebhookEngine.php ✅ Gotowy Przetwarza webhook z FB, wzywa parser
Utils/ TokenGenerator.php ✅ Gotowy generateCheckoutToken(), generateClientToken()
Validator.php ✅ Podstawowy isValidEmail, isValidPhone, itp.
Enum/ (→ osobne README_ENUM_REFAKTOR.md) ✅ Centralny moduł Zawiera statusy, źródła, kolumny, walidatory
🧱 Zasady projektowe

Wszystkie silniki powinny przyjmować PDO w konstruktorze (lub jako parametr statyczny)

Zamiast global $pdo → jawne przekazanie

Wszędzie: declare(strict_types=1);

Enumy w engine/Enum/, sprawdzane przez EnumValidator

Funkcje wewnętrzne statyczne jeśli nie modyfikują stanu (self::parseDaj(), self::trimQty())

Wszystkie błędy logowane przez logg() z LogEngine

Reużywalność: silnik powinien działać zarówno w panelu, webhooku jak i parserze

📌 Plan dalszej refaktoryzacji
Silnik Co zostało do zrobienia
LiveEngine refactor z source_type, source_channel, rezerwacje
ClientEngine dołączenie metod: ensureOrderStatusNowe, findOrCreateOpenGroupForLive
ProductEngine stock movements, generateAiDescription, availability, ENUM do końca
PaymentEngine pełna obsługa: statusy płatności, retry, integracje
ShippingEngine przypisanie etykiet, integracje z InPost/Furgonetka
WebhookEngine dopisanie IG/WhatsApp/komentarze (rozszerzalność kanałów)
🧰 Narzędzia pomocnicze w engine/Utils/

TokenGenerator.php — generateCheckoutToken(), generateClientToken()

Validator.php — isValidPhone, isValidEmail, normalizeSku (planowane)

EnumValidator.php — sprawdzanie poprawności wartości ENUM

✅ Hasło wywołania (dla pełnego kontekstu)
OLAJ_V4_ENGINE_REFAKTOR
