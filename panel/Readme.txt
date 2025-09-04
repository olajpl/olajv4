✅ README_ENUM_REFAKTOR.md
🧠 Cel

Centralizacja i typizacja wartości typu ENUM w systemie Olaj.pl V4 poprzez silnik engine/Enum/*, eliminując błędy z niepoprawnymi nazwami kolumn, wartościami i umożliwiając łatwą humanizację i refaktor UI.

📁 Katalog engine/Enum/ — aktualne pliki ENUM
Plik	Opis
OrderStatus.php	Statusy zamówień (orders.order_status)
PaidStatus.php	Status płatności zbiorczej (order_groups.paid_status)
PaymentStatus.php	Statusy pojedynczej płatności (payments.status)
ShippingStatus.php	Statusy wysyłki (np. etykieta, tracking – planowane)
MessageStatus.php	Statusy wiadomości (messages.status)
StockMovementType.php	Typy ruchów magazynowych (stock_movements.type)
StockReservationStatus.php	Statusy rezerwacji (stock_reservations.status)
OrderItemSource.php	Źródło dodania produktu (order_items.source_type)
OrderItemChannel.php	Kanał zakupu produktu (order_items.source_channel)
EnumValidator.php	Klasa pomocnicza do walidacji EnumValidator::assert()
Column.php	Zbiór stałych z nazwami kolumn (orders.order_status, ...)
🛠️ Pliki silnika już przerobione pod ENUM
Plik	Status refaktoryzacji ENUM	Uwagi
OrderEngine.php	✅ Pełna refaktoryzacja	Wykorzystuje OrderStatus, OrderItemSource, OrderItemChannel, Column, EnumValidator
Parser/DajHandler.php	✅ Zintegrowany	Dodaje produkt z source_type='parser', source_channel='messenger'
ViewRenderer.php	⚠️ Częściowa integracja	OrderStatus w renderStatusBadge() — do dokończenia refaktor PayChip, renderOrderRow()
ClientEngine.php	🟡 W planie	Jeszcze bez użycia enumów
ProductEngine.php	🟡 Do refaktoryzacji	Możliwe dołączenie: StockMovementType, EnumValidator
💡 Wskazówki wdrożeniowe

EnumValidator: Każdy UPDATE/INSERT z wartością ENUM powinien przechodzić przez EnumValidator::assert().

Column: Zamiast literalnych nazw kolumn ('order_status') używamy Column::ORDER_STATUS — łatwa refaktoryzacja w przyszłości.

Humanizacja: ENUM-y zawierają LABELS, COLORS, ICONS (jeśli mają UI reprezentację).

📌 TODO – pliki do refaktoryzacji ENUM

 ClientEngine.php – np. status ostatniego zamówienia

 LiveEngine.php – source_type, order_status dla live-ów

 WebhookEngine.php – komunikacja i źródła wiadomości

 PaymentEngine.php – status płatności

 ShippingEngine.php – statusy i typy dostaw

 LogEngine.php – kategoryzacja wpisów logów ENUMem

 ProductEngine.php – stock_movements, availability

✅ Gotowe do użycia w UI

OrderStatus::LABELS

PaidStatus::getColor()

OrderItemChannel::getIcon()

MessageStatus::LABELS (do filtrowania w admin/cw)

EnumValidator::isValid() → walidacja z poziomu panelu