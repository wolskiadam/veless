# Monitoring CRM

W Konfiguracja → E-mail ustaw adres odbiorcy alarmów i zaznacz włączenie alarmów.
Wymagane jest działające SMTP. Sam zapis nie wysyła maila.

Dodaj **osobne** zadanie cron co 5 minut uruchamiające PHP CLI z pełną ścieżką
do `cli/monitor.php` na serwerze. Nie zastępuje ono crona `cli/worker.php`.
Nie uruchamiaj monitora przez przeglądarkę.

Monitor zgłasza brak rozpoczęcia przebiegu workera przez ponad 10 minut oraz
zadania kolejki ze statusem failed. Wysyła powiadomienie o ustąpieniu problemu.
Powtarza trwający alarm najwyżej co 6 godzin. Próby wysyłki są ograniczone do
jednej na 10 minut (także przy błędzie SMTP). Nie naprawia ani nie ponawia zadań.
Kod wyjścia: 0 — zdrowy, 1 — wykryty problem, 2 — błąd sprawdzenia/wysyłki.

Ograniczenia: sygnał workera potwierdza rozpoczęcie, nie ukończenie wszystkich
zadań. Niedostępność bazy uniemożliwia odczyt ustawień SMTP; awaria całego hostingu
lub jego crona także wymaga niezależnego zewnętrznego monitoringu.

Test lokalny bez bazy i wysyłania wiadomości: `php tests/operational_health.php`.
Nie uruchamiano wysyłki produkcyjnej ani nie konfigurowano crona serwera.

## Pozostały zakres

Kod kopii zapasowych i lokalny test odtwarzania opisuje `BACKUPS.md`; konfiguracja
na serwerze, kopia off-site i harmonogram nie zostały uruchomione.

## Zwroty i korekty

Zamówienia → Zwroty (`returns.php`). Zwroty zgłoszone na Allegro pobierają się same co 30 minut
(`GET /order/customer-returns`, ostatnie 60 dni; proces „Zwroty z Allegro” można wstrzymać w Obciążeniu serwera).
Zwrot do zamówienia ze sklepu dodaje się przyciskiem „Zgłoś zwrot” na stronie zamówienia.

Każdy zwrot ma trzy kroki, każdy tylko po kliknięciu operatora:
1. Przyjęcie na stan: dolicza sztuki do stanu CRM (po SKU) i kolejkuje wysłanie stanu do sklepu. Jednorazowe.
2. Korekta w wFirma: do faktury (typ „normal”) wystawionej z CRM. Ekran potwierdzenia pokazuje pozycje faktury
   pobrane z wFirma i kwotę korekty. Paragony i faktury spoza CRM koryguje się w wFirma.
3. Zwrot pieniędzy: Allegro (`POST /payments/refunds`, aplikacja musi mieć uprawnienie
   `allegro:api:payments:write`), sklep WooCommerce (zapis zwrotu, opcjonalnie przez bramkę płatności) albo
   odnotowanie przelewu zrobionego poza CRM.

Korekta i zwrot pieniędzy są rezerwowane w bazie przed wysłaniem żądania. Brak odpowiedzi lub błąd serwera
zostawia stan „do sprawdzenia” i blokuje ponowienie; administrator rozstrzyga go na stronie zwrotu po sprawdzeniu
w wFirma / Allegro / sklepie. Samo ustawienie statusu zamówienia niczego nie zwraca ani nie koryguje.

Sprawdzone tylko na atrapach API (`php tests/order_returns.php`). Na prawdziwych kontach nie sprawdzono: kształtu
odpowiedzi zwrotów Allegro, zwrotu płatności przez Allegro, faktury korygującej w wFirma (`type=correction`,
`parent_id`) ani zwrotu w WooCommerce.
