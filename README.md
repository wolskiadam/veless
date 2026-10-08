# Veless

Otwarty system do obsługi sklepu internetowego: zamówienia z WooCommerce, Allegro i TikTok Shop
w jednej liście, magazyn i stany, pakowanie ze skanerem, faktury i paragony w wFirma, wysyłki,
płatności PayU, wiadomości z klientem i automatyzacje. Działa na zwykłym hostingu z PHP 8.1+
i MySQL/MariaDB albo w Dockerze na własnym komputerze.

> Kolejka zadań działa na tabeli MySQL (`job_queue`), a worker uruchamia **cron**, więc nie trzeba
> VPS-a, Redisa ani Node (webhooki → kolejka → worker → idempotencja → retry/backoff).

## Gdzie zainstalować: hosting czy własny komputer

Ten sam kod działa na hostingu (domyślnie, zamówienia przychodzą webhookami) albo w Dockerze na
własnym komputerze (`APP_MODE=local`, CRM sam odpytuje kanały co minutę, dane zostają na
komputerze). Porównanie i instalacja jedną komendą: [docker/README.md](docker/README.md).

## Struktura

```
config/    bootstrap (autoloader + .env), fabryka PDO
public/    JEDYNY katalog w document root: webhooki, OAuth callback, health-check
src/
  Support/     Env, Logger, Http (cURL)
  Repository/  integrations, product_mappings, order_logs
  Queue/       Queue (kolejka na MySQL), JobStatus
  Services/    AllegroService, WooService, WfirmaService  
  Handlers/    NewAllegroOrderHandler (Moduł 2), StockSyncHandler (Moduł 3)
cli/       worker.php (JEDYNY wymagany cron, co 1 min - kolejka + Scheduler),
           refresh_tokens.php, poll_allegro_orders.php (opcjonalne, do ręcznego użycia)
sql/       schema.sql (MySQL)
storage/   logi i plik locka workera
```

## Import historyczny (wszystkie istniejące zamówienia)

Webhook pobiera tylko **nowe** zamówienia (od momentu konfiguracji). Aby ściągnąć
**istniejące**, użyj panel → Zamówienia → **„Importuj z WooCommerce"** (`import_woo.php`).

- Wybierasz zakres: wszystkie / tylko aktywne / ostatnie 30 dni.
- System pobiera zamówienia stronami przez API i wrzuca każde do kolejki
  (`woo.order.import`) — worker je zapisuje. Wymaga działającego crona workera.
- **Idempotentne:** ten sam `dedup_key` co webhook, więc powtórny import ani nakładanie
  się z webhookiem nie tworzą duplikatów.
- Dla bardzo dużej historii strona odświeża się automatycznie po każdej porcji (omija
  limit czasu PHP).

## Magazyn / produkty (CRM jako master) — etap 1: import

CRM ma być centralnym magazynem (źródłem prawdy dla produktów, stanów, cen).
**Etap 1: import produktów z WooCommerce** do tabeli `products` (po SKU).

- **Panel:** Magazyn → Import produktów (wybór sklepu) → pobiera produkty stronami,
  kolejkuje (`woo.product.import`), worker zapisuje do `products`. Produkty **bez SKU**
  są pomijane (SKU = klucz mastera między kanałami).
- **Lista:** Magazyn → Produkty — SKU, nazwa, stan (CRM), cena (CRM), z ręczną edycją.
- **Master:** `pase_stock` / `pase_price` to wartości zarządzane w CRM. Przy imporcie
  ustawiane z Woo **tylko gdy puste** (pierwszy raz); później CRM jest źródłem prawdy
  i import ich nie nadpisuje.

**Pełne dane produktu.** Import zapisuje też pola potrzebne do wystawiania ofert na
marketplace: `description` (opis pełny), `short_description`, `images` (galeria URL),
`categories`, `attributes`, `ean`, `weight`, `length`/`width`/`height`. Cały surowy
payload jest też w `payload` (JSON). Podgląd: Magazyn → Produkty → „Podgląd"
(`product_view.php`) — tytuł, opisy, galeria, kategorie, atrybuty, EAN, wymiary.

> Pola opisowe (z Woo) są aktualizowane przy każdym imporcie. Stany/ceny CRM
> (`pase_stock`/`pase_price`) są chronione — ustawiane tylko przy pierwszym imporcie.

### Własne pola produktu

Magazyn → **Pola produktów** (`product_fields.php`) — definiujesz własne pola
(typ: tekst / liczba / lista wyboru). Wartości wypełniasz per produkt w podglądzie
(Magazyn → Produkty → Podgląd → sekcja „Własne pola").

- Definicje w tabeli `product_fields`; wartości per produkt w `products.custom_fields` (JSON
  `{klucz: wartość}`).
- Wartości **nie są** nadpisywane przy reimporcie z Woo (to dane CRM). Usunięcie definicji
  pola nie kasuje wartości w danych, tylko ukrywa pole z formularza.
- Przydatne do danych spoza Woo: kod producenta, parametry pod Allegro itp.

**Pola przypisane do kategorii.** Przy definicji pola możesz zaznaczyć kategorie produktów
(lista kategorii pochodzi z zaimportowanych produktów Woo — kolumna `products.categories`).
- Pole **bez zaznaczonych kategorii = globalne** (widoczne we wszystkich produktach).
- Pole **z kategoriami** pokazuje się tylko przy produktach należących do którejś z nich.
- Lista kategorii w `product_fields.categories` (JSON). Przy produkcie pola filtruje
  `ProductFieldRepository::forCategories()` wg `products.categories`.

### Kategorie produktów

Magazyn → **Kategorie** (`categories.php`) — przegląd kategorii zaimportowanych z Woo:
liczba produktów w każdej kategorii oraz przypisane do niej własne pola (plus lista pól
globalnych). Liczbę produktów klikasz, by przejść do listy odfiltrowanej po kategorii.

### Synchronizacja stanów CRM → WooCommerce

- **Kierunek** ustawiasz w integracji Woo: pole „Źródło prawdy dla stanów" — `PASE`
  (CRM wypycha stany do sklepu) lub `Sklep` (CRM nie nadpisuje).
- **Wyzwalanie:** po zapisie stanu w Magazyn → Produkty (masowy zapis) CRM kolejkuje
  `woo.stock.push` per produkt; worker wysyła stan do Woo (`updateProductStock`).
- **Ochrona przed pętlą:** import/webhook z Woo NIE nadpisuje `pase_stock` (upsert używa
  `COALESCE` — stan CRM ustawiany tylko przy pierwszym imporcie). Webhook `product.updated`
  z Woo nie modyfikuje tabeli `products`. Dzięki temu nie ma pętli CRM↔Woo.
- **Na razie tylko stany** (nie ceny — bardziej wrażliwe, osobny etap).

> Kolejne etapy: ceny CRM → sklep, odejmowanie stanu przy zamówieniu (anty-overselling),
> sync do Allegro, warianty produktów, wystawianie ofert na Allegro z CRM.

## Integracja kurierska — BLPaczka (fundament)

BLPaczka to broker kurierski (InPost/DPD/DHL… przez jedno API). **Etap 1: fundament + połączenie.**

- **Dodanie:** Integracje → Dodaj integrację → typ **BLPaczka**. Pola: login (e-mail z konta
  BLPaczka), klucz API, środowisko (`sandbox`/`production`).
- **Auth:** każde żądanie wysyła `{ "auth": { "login": ..., "api_key": ... } }` (wg `AuthDto`).
- **Test połączenia:** przycisk w panelu integracji — odpytuje `getBankSaldo.json` (saldo prepaid);
  200 + poprawna odpowiedź = login/klucz działają.
- **Środowiska:** sandbox → `https://sandbox.blpaczka.com`, produkcja → `https://api.blpaczka.com`.

**Etap 2: nadawanie przesyłek.** Na stronie zamówienia, sekcja „Przesyłki":
- **Dane nadawcy** ustawiasz raz w integracji BLPaczka (config['sender']).
- **Przepływ:** uzupełnij paczkę (waga, wymiary, kod kuriera) → **Wyceń** (`getValuation`,
  bez kosztów) → **Nadaj paczkę** (`createOrderV2`, REALNE — pobiera środki z prepaid,
  z potwierdzeniem). Nadana przesyłka zapisuje się w tabeli `shipments` (nr listu, koszt,
  link do etykiety) i pokazuje przy zamówieniu.
- Endpoint: `shipment_create.php` (akcje `quote` / `send`).

> ⚠️ Produkcja = realne przesyłki i koszty. Mapowanie pól odbiorcy bierze się z zamówienia
> (billing/shipping). Dokładne kody kurierów i wymagane pola zależą od BLPaczka — przy
> pierwszym nadaniu sprawdź odpowiedź API (logowana w storage/app.log) i dostrój.

Kolejne etapy: etykieta (`getWaybill`), śledzenie (`getWaybillTracking`), punkty PUDO.

## Integracja kurierska — ORLEN Paczka

Wtyczka `integrations/orlenpaczka/` nadaje paczki do punktów i automatów ORLEN Paczka na **własnej umowie**
z ORLEN Paczka (API SOAP, dokumentacja API ORLEN Paczka v1.26). Paczki z umowy Allegro (Allegro SMART)
nadaje się dalej przez „Wysyłam z Allegro”.

- **Dodanie:** Integracje → Dodaj integrację → **ORLEN Paczka**. Pola: PartnerID i PartnerKey (z e-maila po
  aktywacji umowy; to samo hasło co w WebTrucker), środowisko (produkcyjne / testowe — inne dane dostępowe),
  dane nadawcy, domyślny gabaryt (S/M/L albo dobór z wymiarów), format etykiety (PDF 10×15 / A4, ZPL 203/300 dpi),
  opcjonalnie ceny gabarytów z umowy. **Test połączenia** odpytuje `GivePartnerStatus` (konto, POSTPAID/PREPAID).
- **Nadanie:** karta zamówienia → Przesyłki → Nadaj przez → ORLEN Paczka. Punkt odbioru bierze się z zamówienia
  (WooCommerce/Allegro; sam numer punktu zamienia się na pełny kod), można go wpisać albo wyszukać po mieście,
  kodzie pocztowym czy ulicy. Lista punktów (`GiveMeAllLocationWithAllDataWithZipCode`) pobiera się przy pierwszym
  wyszukiwaniu i odświeża raz dziennie po 6:00 (`storage/cache/orlenpaczka-points.json`).
  Awizacja z etykietą: `GenerateLabelBusinessPackListTwo`; etykieta ponownie: `LabelPrintDuplicateListTwo`;
  anulowanie: `PutCustomerPackCanceled`. Opcja „Zamówię podjazd kuriera” po nadaniu zamawia odbiór
  (`GetAvailablePickups` + `CallPickupNew`, pierwszy wolny przedział od wybranego dnia).
- **Śledzenie:** harmonogram i przycisk ↻ pytają ORLEN Paczka (`GiveMePackStatusFullHistoryList`) o paczki nadane
  przez wtyczkę i o paczki ORLEN wpisane ręcznie; czego ORLEN nie zna, sprawdzamy jak dotąd przez Allegro.
- Nie obsługujemy: zwrotów konsumenckich, nadania bez etykiety (kod nadania), protokołu przekazania.
  COD i ubezpieczenie ORLEN Paczka wycofała — wtyczka ich nie wysyła.

## Multi-integracja (wiele sklepów)

CRM obsługuje **wiele integracji**, także kilka tego samego typu (np. kilka sklepów
WooCommerce). Każda integracja to wiersz w tabeli `integration_accounts` z własnymi
kluczami (`config` JSON), własnym sekretem webhooka i własnym statusem aktywności.

- **Panel:** Integracje → lista wszystkich + „Dodaj integrację”. Edycja: `integration_edit.php`.
- **Webhook per sklep:** każdy sklep ma własny adres
  `…/webhook_woo_order.php?integration=ID` (ID pokazuje strona edycji integracji).
  Dzięki temu CRM wie, z którego sklepu pochodzi zamówienie (`woo_orders.integration_id`).
- **Import historyczny:** wybierasz sklep z listy przed importem.
- **Sync statusu:** przełącznik `sync_status` jest **per integracja** (każdy sklep osobno).
- **Migracja:** przy pierwszym uruchomieniu po aktualizacji dotychczasowa konfiguracja Woo
  z `settings` jest automatycznie przenoszona do integracji „Sklep WooCommerce”, a istniejące
  zamówienia są do niej podpinane.

> Uwaga: stary blok `woo` w `config/config.php` i klucze w `settings` pozostają jako
> fallback dla nieprzeniesionych jeszcze przepływów (sync stanów magazynowych - Moduł 3).
> Pobieranie zamówień, import i sync statusu działają już w pełni per-integracja.

## Sprawdzenie połączenia

Są dwa niezależne kierunki — warto sprawdzić oba:

1. **CRM → sklep (klucze API):** panel → Integracje → WooCommerce → **„Testuj połączenie"**.
   Realne zapytanie do REST API sklepu. Wynik: ✅ „Połączenie OK" lub ❌ z kodem (401 = złe
   klucze, 404 = zły adres / brak WooCommerce, 0 = brak połączenia z serwerem).
2. **Sklep → CRM (webhook):** WP Admin → WooCommerce → Ustawienia → Zaawansowane → Webhooki
   → otwórz webhook → sekcja dostarczeń. **Kod 200** = nasz serwer przyjął webhook; **401** =
   niezgodny sekret; **404/500** = zły URL lub błąd po naszej stronie.

Pobieranie zamówień wymaga, by **oba** działały oraz by **cron workera** był uruchomiony.

## Klucze API — edycja z panelu

Klucze WooCommerce, Allegro i wFirma ustawisz w panelu → **Integracje** (zapisują się do
tabeli `settings` w bazie). Nie musisz już wpisywać ich w `.env`.

- **Pierwszeństwo:** wartość z bazy (panel) > wartość z `.env` > domyślna. Dzięki temu
  `.env` nadal działa jako fallback, a panel go nadpisuje bez ruszania pliku.
- **Sekrety** (consumer secret, klucze) nie są nigdy pokazywane w całości — jeśli wartość
  jest już zapisana, pole pokazuje placeholder; puste pole przy zapisie = „nie zmieniaj".
- Aplikacja **nie modyfikuje `.env`** — zapis idzie do bazy (zgodnie z zasadą: bazę system
  aktualizuje sam, plików nie rusza).

## Auto-migracje bazy

System **sam tworzy i aktualizuje tabele** w bazie przy pierwszym połączeniu w danym
procesie (`src/Support/Migrator.php`, wołane z `config/database.php`). Działa idempotentnie
(`CREATE TABLE IF NOT EXISTS`) — nie kasuje danych ani nie rusza istniejących tabel.

Oznacza to: **nie musisz importować plików `.sql` ręcznie**. Wystarczy, że plik kodu
trafi na serwer (FTP) — przy najbliższym wejściu na panel / webhook / przebiegu workera
brakujące tabele powstaną same.

- Pliki `sql/schema.sql` i `sql/migration_*.sql` pozostają jako **czytelna referencja**
  schematu (oraz awaryjny ręczny import, gdyby użytkownik bazy nie miał uprawnień `CREATE`).
- Podział pracy: **bazę aktualizuje system sam; pliki kodu wgrywasz przez FTP** (aplikacja
  celowo nie modyfikuje własnych plików — to byłoby zagrożenie bezpieczeństwa).

## Wersja demonstracyjna (DEMO_MODE)

Publiczne demo, w którym każdy może się zalogować (np. demo.veless.pl). Instalacja jak zwykle,
ale z `DEMO_MODE=1` w `.env`, a zamiast workera w cronie jest tylko nocny reset:

```
0 3 * * *  php /ścieżka/do/veless/cli/demo_reset.php
```

`cli/demo_reset.php` usuwa wszystkie tabele i pliki robocze, tworzy bazę od nowa i wgrywa fikcyjny
„Sklep Demo” (`src/Services/DemoData.php`: 12 produktów, ~140 zamówień z ostatniego miesiąca,
przesyłki, dokumenty, automatyzacje). Uruchom go raz po wgraniu plików. Bez `DEMO_MODE=1` odmawia
działania, więc nie wyczyści prawdziwego systemu.

W trybie demo (`src/Support/Demo.php`):
- na stronie logowania jest przycisk „Wejdź do demo” (login i hasło: `demo`), a w panelu pasek z informacją,
- nic nie wychodzi na zewnątrz: zapytania HTTP do API, wyszukiwarka firm i wysyłka e-maili są wyłączone,
- serwer nie przyjmuje żadnych plików (wtyczki, załączniki, CSV, kopie),
- kopie zapasowe, migracja adresu, pobieranie agenta i reset hasła są wyłączone,
- konta, 2FA, wtyczki, integracje i ustawienia wysyłki można oglądać, ale nie zmieniać.

## Instalacja na hostingu współdzielonym

Logowanie dwuetapowe zgodne z Google Authenticator: [konfiguracja i wdrożenie 2FA](TWO-FACTOR.md).

1. **Wgraj pliki** na serwer (FTP/menedżer plików). **Document root** ustaw na katalog
   `public/`. Reszta (`.env`, `src/`, `cli/`) zostaje poza zasięgiem weba — to celowe
   ze względów bezpieczeństwa.
2. **Utwórz bazę** MySQL w panelu hostingu (zapisz nazwę bazy, użytkownika, hasło).
3. **Uruchom instalator przez SSH/CLI:** `php cli/install.php` (interaktywnie pyta
   o dane bazy). Instalator webowy jest wyłączony, również z parametrem `force`.
   Bez SSH: skopiuj `.env.example` do `.env`, wpisz dane bazy i zaimportuj
   `sql/schema.sql` przez panel hostingu. Nie udostępniaj `.env` przez HTTP.
4. **Uzupełnij klucze API** w wygenerowanym `.env` (Allegro, WooCommerce, wFirma).
5. **Composer niepotrzebny** — `config/config.php` rejestruje własny autoloader PSR-4.

> **Gdzie podaję dane bazy?** Albo w instalatorze CLI, albo ręcznie w pliku `.env`
> (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) skopiowanym z `.env.example`. Dane bierzesz
> z panelu hostingu → „Zarządzanie bazami MySQL" (nazwy mają zwykle prefiks loginu).

### Cron (panel DirectAdmin)

**Wystarczy JEDEN wpis** — worker odpala Scheduler, który pobiera zamówienia ze wszystkich
kanałów (sklepy WooCommerce + Allegro) i odświeża tokeny OAuth:

   ```cron
   * * * * * /usr/bin/php /home/USER/pase/cli/worker.php >> /home/USER/pase/storage/cron.log 2>&1
   ```

Skrypty `cli/refresh_tokens.php` i `cli/poll_allegro_orders.php` nadal działają i można je
uruchamiać ręcznie, ale osobne wpisy w cronie nie są już potrzebne. Gdyby zostały ze starszej
konfiguracji, nic się nie zepsuje: kursor zdarzeń Allegro jest wspólny, a klucze dedup
w kolejce chronią przed podwójnym importem.

> **Uwaga na ścieżkę logu.** Przekierowanie `>>` do katalogu, który nie istnieje, sprawia, że
> powłoka przerywa **całą linię** — PHP nigdy się nie uruchamia i nie ma po tym żadnego śladu.
> Katalog `storage/` musi istnieć pod dokładnie tą ścieżką, którą wpisujesz w cronie.
> Czy cron faktycznie chodzi, sprawdzisz w panelu: **Konfiguracja → Synchronizacja**
> (pasek u góry pokazuje czas ostatniego przebiegu workera).

## Pobieranie zamówień z WooCommerce

System pobiera zamówienia ze sklepu przez webhook i zapisuje je w tabeli `woo_orders`
(widoczne w panelu → „Zamówienia Woo"). Na razie tylko import + podgląd; automatyzacje
(co dalej z zamówieniem) dodamy później na bazie zapisanego payloadu.

**Konfiguracja (jednorazowa):**

1. **Migracja bazy:** nic nie musisz robić — tabela `woo_orders` powstanie
   automatycznie przy pierwszym uruchomieniu (patrz „Auto-migracje" niżej).
2. **Sekret webhooka:** w `.env` ustaw `WOO_WEBHOOK_SECRET` na losowy ciąg.
3. **W WooCommerce** (WP Admin → WooCommerce → Ustawienia → Zaawansowane → Webhooki →
   *Dodaj webhook*):
   - **Temat:** „Zamówienie utworzone" (`order.created`) — opcjonalnie dodaj drugi na
     „Zamówienie zaktualizowane" (`order.updated`).
   - **Adres URL dostawy:** `https://twojsklep.pl/pase/public/webhook_woo_order.php`
   - **Sekret:** ta sama wartość co `WOO_WEBHOOK_SECRET` w `.env`.
   - **Wersja API:** WP REST API v3.
4. **Test:** złóż testowe zamówienie w sklepie. W ciągu ~1 min (przebieg workera)
   powinno pojawić się w panelu w „Zamówienia Woo". Jeśli nie — sprawdź zakładkę
   „Kolejka" (czy zadanie `woo.order.import` jest pending/failed) i logi w `storage/`.

> Webhook **zamówień** (`webhook_woo_order.php`) jest oddzielny od webhooka **stanów
> magazynowych** (`webhook_woo.php`, Moduł 3 — Woo→Allegro). Możesz skonfigurować jeden
> lub oba, zależnie od potrzeb.

## Statusy zamówień (własne + kolory)

Statusy żyją w tabeli `order_statuses`: **5 systemowych** (Nowe, W realizacji, Wysłane,
Anulowane, Zwrot — zaseedowane, nieusuwalne, powiązane z mapowaniem Woo) + dowolne
**własne** dodane przez usera. Każdy status ma kolor (badge na liście) i kolejność.

- **Zarządzanie:** Konfiguracja → „Statusy zamówień" (`statuses.php`) — dodawanie/edycja/usuwanie
  własnych, zmiana koloru/nazwy systemowych.
- **Zmiana statusu na liście:** pasek akcji z ikoną flagi 🚩 → dropdown kolorowych statusów,
  zmienia status zaznaczonych (checkboxy). Plus dropdown w szczegółach zamówienia.
- **Sync do Woo** działa tylko dla statusów **systemowych** (mają mapowanie CRM→Woo).
  Własne statusy są lokalne (CRM) — sync ich pomija.

## Status zamówienia (ręczna zmiana + sync do Woo)

Status zamówienia w CRM to **jedno źródło prawdy** (`woo_orders.pase_status`), z 5 wartości:
Nowe / W realizacji / Wysłane / Anulowane / Zwrot. Status Woo jest mapowany na status CRM
przy imporcie; przy kolejnych webhookach status CRM **nie jest** nadpisywany (CRM rządzi).

Ręczna zmiana:
- **Szczegóły zamówienia** (`order_view.php`) — sekcja „Status zamówienia": dropdown + „Zmień status".
- **Lista** — checkboxy przy wierszach + „Zmień status zaznaczonych" (operacja zbiorcza).

**Synchronizacja do WooCommerce:** opcjonalna, przełącznik w Integracje → WooCommerce
(„Synchronizuj zmianę statusu z powrotem do WooCommerce"). Gdy włączona, zmiana statusu w
CRM wysyła zmapowany status (CRM → Woo) do sklepu. Gdy wyłączona — zmiana zostaje lokalna.
Sync jest best-effort: błąd po stronie Woo nie cofa zmiany w CRM (jest logowany).

### Archiwum i Kosz (cykl życia zamówienia)

Kolumna `woo_orders.lifecycle`: `active` (lista) | `archived` (Archiwum) | `trashed` (Kosz).
Panel boczny listy zamówień ma pozycje **📦 Archiwum** i **🗑 Kosz** z licznikami.

- **Auto-archiwizacja:** worker przenosi do Archiwum aktywne zamówienia starsze niż **90 dni**
  (wg `date_created`). Sprawdzane ~raz na godzinę (`SettingsRepository` klucz
  `ORDERS_AUTOARCHIVE_AT`); metoda `WooOrderRepository::autoArchiveOlderThan(90)`.
- **Kosz (usuwanie miękkie):** „Do kosza" ustawia `trashed` — zamówienie znika z list, ale można
  je **przywrócić**. **Trwałe usunięcie** (`DELETE`) jest możliwe tylko z poziomu Kosza
  (pojedynczo, masowo lub „Opróżnij kosz") — `purgeMany()` / `emptyTrash()`.
- **Akcje** (tylko `editor`/`admin`): masowe (zaznaczone) i per-wiersz — archiwizuj / do kosza /
  przywróć / usuń trwale, zależnie od bieżącego widoku.

### Synchronizacja cykliczna (interwały)

Konfiguracja → **Synchronizacja** (`sync_settings.php`) — interwały (w minutach, `0`=off) dla
czterech przepływów. Wykonuje je **worker** przez `Pase\Services\Scheduler` (wołany na początku
każdego przebiegu): sprawdza, którym przepływom minął interwał, kolejkuje ich zadania i zapisuje
znacznik ostatniego uruchomienia. Klucze: `SYNC_ORDERS_EVERY`, `SYNC_STOCK_EVERY`,
`SYNC_PRICE_EVERY`, `SYNC_PRODUCTS_EVERY` (+ `SYNC_*_AT`).

- **Pobieranie zamówień** — `OrderSource::fetchOrders` z `after` = `MAX(date_created)` danej
  integracji (tylko nowsze), kolejkuje `woo.order.import` (dedup chroni przed duplikatami).
- **Sync stanów / cen** — kolejkuje `woo.stock.push` per produkt z `what` = `stock`/`price`.
  `WooStockPushHandler` wypycha stan i/lub cenę przez `Warehouse::pushStock` / `pushPrice`
  (cena = `regular_price`; tylko gdy `stock_master='pase'`).
- **Auto-import produktów** — `Warehouse::fetchProducts`, kolejkuje `woo.product.import`.

Częstotliwość nie spadnie poniżej rozdzielczości crona (1 min). Webhook nadal pobiera nowe
zamówienia natychmiast; pobieranie cykliczne jest zabezpieczeniem na nieprzyjęte webhooki.

## Automatyzacje (ZDARZENIE + warunki → lista akcji)

Panel → Konfiguracja → **Automatyzacje** (`automations.php`). Model jak w BaseLinkerze:
**reguła = zdarzenie + warunki + ponumerowana lista akcji**. Edycja inline w wierszu listy
(zdarzenie i warunki po lewej, lista akcji w środku, zapis/anuluj po prawej). Każdy wiersz ma
przełącznik aktywności, duplikowanie i usuwanie.

- **Zdarzenie (trigger)** — co uruchamia regułę. Katalog w `src/Automation/Catalog.php`:
  `order.imported` (Pobrano zamówienie), `status.changed` (Ustawiono status), `order.paid`
  (Opłacone), `shipment.created`/`shipment.status` (przesyłka u kuriera), `invoice.issued`
  (Wystawiono fakturę). Realnie wyzwalane dziś: **order.imported** (po imporcie z Woo) oraz
  **status.changed** (po ręcznej zmianie statusu CRM). Pozostałe są w katalogu, ale ich
  wyzwalanie podłączymy razem z integracjami BLPaczka/wFirma.
- **Warunki** (łączone AND, dowolna liczba): pole (`status` Woo / `total` suma / `source`
  źródło / `sku` pozycji) + operator (=, ≠, >, ≥, <, ≤, zawiera) + wartość. Brak warunków
  = pasuje zawsze.
- **Akcje** (lista, wykonywane po kolei). Realnie działają **`set_status`** (status CRM) oraz
  **`send_email`** (wysyłka wg szablonu do klienta zamówienia — patrz niżej). Pozostałe
  (`send_sms`, `issue_invoice`, `create_receipt`, `forward_order`) są w katalogu i można je
  dodać do reguły, ale worker je na razie **pomija** (oznaczone „wkrótce”); flaga `ready`
  w `Catalog::actions()`.
- **Wykonanie:** worker (lub serwis statusów) woła `RuleEngine::dispatch($event, …)`, który
  bierze aktywne reguły danego zdarzenia wg `priority` (mniejsza liczba = wcześniej),
  sprawdza warunki i wykonuje listę akcji. `order.imported` jest idempotentne — raz na
  zamówienie (flaga `automations_done`).
- **Schemat:** `automation_rules` ma kolumny `event` i `actions` (JSON listy `[{type,params}]`).
  Stare kolumny `action_type`/`action_params` są utrzymywane dla zgodności wstecz i przy
  migracji przepisywane do `actions` (`Migrator::backfillAutomationActions()`).
- **Rozszerzalność:** nowa akcja = wpis w `Catalog::actions()` (z `ready=>true`) + `case`
  w `RuleEngine::executeAction()`. Nowe zdarzenie = wpis w `Catalog::events()` + wywołanie
  `dispatch()` w odpowiednim miejscu przepływu.

> Reguły `order.imported` działają na zamówieniach pobranych **po** ich utworzeniu/aktywacji.
> Istniejące zamówienia mają już `automations_done=1`; by przepuścić je ponownie, wyzeruj tę flagę.

## E-mail i szablony

**Wysyłka** odbywa się przez **SMTP** (lekki klient w `src/Services/Mailer.php` — bez Composera/
PHPMailera, działa na shared hostingu). Obsługuje STARTTLS (587), SSL/TLS (465) i bez szyfrowania
(25), AUTH LOGIN oraz treść `multipart/alternative` (HTML + automatyczna wersja tekstowa).

- **Konfiguracja:** Konfiguracja → **E-mail (SMTP)** (`email_settings.php`). Ustawienia w tabeli
  `settings` (klucze `MAIL_SMTP_HOST/PORT/USER/PASS/SECURE`, `MAIL_FROM_EMAIL/NAME`). Hasło
  zapisujemy tylko przy zmianie (puste pole = bez zmian). Przycisk „Zapisz i wyślij test"
  wysyła próbną wiadomość.
- **Szablony:** Konfiguracja → **Szablony e-mail** (`email_templates.php`, tabela `email_templates`).
  Pola: nazwa, `tpl_key` (auto ze sluga, stabilny identyfikator do reguł), temat, treść (HTML),
  aktywny. Przycisk „Podgląd" renderuje szablon na przykładowych danych. Jeden przykładowy
  szablon (`order_processing`) jest seedowany przy migracji.
- **Zmienne** `{{...}}` podstawiane przy wysyłce (z danych zamówienia): `customer_name`,
  `customer_email`, `order_number`, `pase_number`, `total`, `currency`, `status`, `shop_name`.
- **Użycie:** w regule automatyzacji dodaj akcję **„Wyślij e-mail"** i wybierz szablon z listy.
  Worker (RuleEngine) renderuje temat/treść, wyznacza adres klienta (z `billing.email`, w razie
  braku z `woo_orders.customer_email`) i wysyła. Brak SMTP / nieaktywny szablon / brak adresu =
  akcja jest pomijana z wpisem w logu (nie blokuje pozostałych akcji reguły).
- **Monitoring wysyłki:** System → **Wysłane e-maile** (`email_log.php`, admin i edytor). Każda próba
  wysyłki przez `Mailer::send()` trafia do tabeli `email_log` (`Pase\Services\EmailLog`): rodzaj
  (automatyzacja, wiadomość do klienta, powiadomienie o wiadomości klienta, reset hasła, test SMTP,
  alarm monitora), szablon, odbiorca, temat, konto nadawcy, zamówienie, kto wywołał, status i powód
  błędu. Bez treści maila. Strona pokazuje liczby wg rodzaju, szablonu, nadawcy i dnia (czas polski)
  oraz listę z filtrami; z zamówienia prowadzi przycisk „Wysłane e-maile". Wpisy starsze niż rok są
  usuwane. Nowe miejsce wysyłki podaje rodzaj w 4. argumencie `send(..., ['type' => ..., 'order_id' => ...])`.

## Wiadomości z klientem i strona zamówienia klienta

Każde zamówienie ma **wątek korespondencji** (tabela `order_messages`) i **publiczną stronę dla
klienta** dostępną bez logowania przez długi losowy token (`woo_orders.client_token`).

- **Strona klienta:** `public/order.php?token=…` — pokazuje status (pasek kroków), produkty z
  miniaturami (z magazynu, po SKU), kwoty, adres dostawy, **śledzenie przesyłki** (nr listu +
  status), **wątek wiadomości** i formularz z załącznikiem. Styl zbliżony do panelu klienta
  BaseLinkera; `noindex`, własny layout. Token nadawany przy imporcie oraz dla istniejących
  zamówień w migracji (`backfillClientTokens`).
- **Weryfikacja tożsamości:** sam token nie wystarcza — po wejściu klient wpisuje **jedną daną
  z zamówienia** (adres e-mail albo numer telefonu) w jednym polu; system sam rozpoznaje, którą
  podał (`ClientVerify::matchesAny`). Porównanie jest odporne na formatowanie (wielkość liter,
  spacje, prefiks 48 w telefonie). Po sukcesie dostęp jest zapamiętany w **sesji** (do zamknięcia
  przeglądarki). Anty-zgadywanie: **5 prób / 15 min** na zamówienie, potem czasowa blokada.
  Weryfikację można **włączyć/wyłączyć** w Konfiguracji → Strona klienta (`CLIENT_VERIFY_ENABLED`);
  domyślnie włączona. Gdy zamówienie nie ma żadnej danej do porównania (brak e-maila/telefonu),
  bramka jest pomijana.
- **Panel:** w szczegółach zamówienia (`order_view.php`) sekcja **„Wiadomości z klientem"** — cały
  wątek, pole odpowiedzi z załącznikiem oraz gotowy **link dla klienta** do skopiowania.
- **Które zamówienia mają nowe wiadomości:** na liście zamówień (`index.php`) zamówienia z
  nieprzeczytaną wiadomością klienta są **podświetlone** i mają pomarańczowy badge `✉ N` przy
  numerze (klik → podgląd). Filtr **„Tylko z nowymi wiadomościami klienta”** (`?unread=1`) zawęża
  listę. W nawigacji obok 🏠 jest skrót `✉ N` prowadzący wprost do tej przefiltrowanej listy.
  Otwarcie zamówienia oznacza jego wiadomości jako przeczytane.
- **Usuwanie wiadomości przez klienta:** na publicznej stronie klient może usunąć **własne**
  wiadomości (przy każdej swojej jest „usuń”, z potwierdzeniem). Kasowanie jest ograniczone do
  `sender='client'` w obrębie jego zamówienia (nie ruszy odpowiedzi obsługi); usuwany jest też
  ewentualny załącznik z dysku.
- **Powiadomienia e-mail** (`OrderMessageService`): odpowiedź obsługi wysyła e-mail do klienta
  (treść + link do wątku); wiadomość klienta powiadamia administratora (adres
  `MAIL_ADMIN_NOTIFY`, domyślnie `MAIL_FROM_EMAIL`). Wymaga skonfigurowanego SMTP; brak SMTP =
  wiadomość i tak trafia do wątku, tylko bez e-maila.
- **Załączniki** (`Attachments`): pliki trafiają do `storage/uploads/` (poza web-rootem,
  `storage/.htaccess` = deny all), nazwa na dysku losowa. Pobieranie tylko przez skrypty PHP po
  weryfikacji: panel `admin/attachment.php` (zalogowana obsługa), klient
  `order_attachment.php?token=…&id=…` (sprawdza, że wiadomość należy do zamówienia spod tokenu —
  ochrona przed IDOR). Dozwolone: obrazy, PDF, dokumenty; limit 8 MB.
- **Konfiguracja:** Konfiguracja → **Strona klienta** (`client_settings.php`) — włącznik
  weryfikacji (`CLIENT_VERIFY_ENABLED`), **Adres bazowy strony klienta** (`APP_BASE_URL`,
  np. `https://twojsklep.pl/pase/public`, buduje linki w e-mailach) oraz **E-mail powiadomień**
  o wiadomości klienta (`MAIL_ADMIN_NOTIFY`). Konfiguracja → E-mail (SMTP) zawiera już tylko
  ustawienia serwera poczty.

> Token w linku = pełny dostęp do strony zamówienia. Traktuj link jak poufny (wysyłamy go tylko
> na e-mail klienta). Token można w razie potrzeby zresetować, czyszcząc `client_token` —
> przy następnej migracji wygeneruje się nowy.

## Wielojęzyczność (i18n)

Lekka warstwa tłumaczeń: `src/Support/I18n.php` + globalny skrót `t('klucz', ['n'=>1])`.
Słowniki to `lang/<kod>.php` (tablica `['klucz' => 'tekst']`). Język bazowy/zapasowy = **pl**
(brakujący klucz → pl → sam klucz).

- **Strona klienta** (`public/order.php`) i **e-maile do klienta** są wielojęzyczne. Język
  wykrywany automatycznie: kraj zamówienia (`billing.country`, np. GB/US → en) lub
  `Accept-Language`; na stronie jest przełącznik (zapamiętany w sesji per token).
- **Dodanie języka:** skopiuj `lang/pl.php` → `lang/en.php`, przetłumacz wartości, ustaw `'_name'`.
- **Panel administracyjny** jest na razie po polsku (na sztywno) — jego tłumaczenie to kolejny etap.

## Panel administracyjny

Dostępny pod `https://twojadomena.pl/admin/`. Logowanie i role (admin/editor/viewer)
działają na tym samym wzorcu co system **sds-generator** (sesja PHP + tabela `admin_users`).

- **Pierwsze logowanie:** login `admin`. Hasło ustawiasz w `.env`
  (`ADMIN_DEFAULT_PASSWORD`); jeśli puste, przy pierwszym wejściu zostanie wygenerowane
  losowo i zapisane w `storage/ADMIN_PASSWORD.txt` (odczytaj i usuń ten plik).
- **Strony:**
  - **Pulpit** — liczniki integracji, kolejki, zamówień, status workera.
  - **Zamówienia** — dziennik `order_logs` z filtrem statusu i podglądem błędów.
  - **Mapowania SKU** — dodawanie/edycja/usuwanie powiązań SKU ↔ Woo ↔ Allegro
    (bez phpMyAdmin).
  - **Integracje** — „Połącz z Allegro" (OAuth) i status kluczy Woo/wFirma.
  - **Kolejka** — podgląd `job_queue`, ręczne ponawianie zadań `failed`.

## Endpointy (URL-e do wpięcia w panelach)

| Cel | URL |
|---|---|
| Health-check | `GET  /index.php` |
| Allegro OAuth callback | `GET  /auth_allegro_callback.php` |
| Allegro webhook (ORDER_NEW) | `POST /webhook_allegro.php` |
| WooCommerce webhook (product.updated) | `POST /webhook_woo.php` |

## Realizacja wymagań niefunkcjonalnych

- **Idempotencja:** `order_logs` z `UNIQUE(source_platform, source_order_id)` +
  `OrderLogRepository::claim()`; dodatkowo dedup na poziomie kolejki (`job_queue.dedup_key`).
- **Resilience:** `Queue::fail()` — 3 próby, wykładniczy backoff (60s → 120s → 240s),
  potem status `failed` (dead letter). Zawieszone zadania odzyskuje `recoverStuck()`.
- **Rate-limit Allegro:** `worker.php` utrzymuje minimalny odstęp między żądaniami
  (`ALLEGRO_MIN_INTERVAL_MS`) + kolejka opóźnia start przez `available_at`.
- **Logowanie:** `Logger` zapisuje każdą operację i odpowiedź API ze Status Code
  (CLI → STDERR/cron.log, web → `storage/app.log`).

## Jak pomóc

Każdy może zgłaszać błędy i pomysły oraz przesyłać poprawki przez pull request –
zasady są w [CONTRIBUTING.md](CONTRIBUTING.md), a luki bezpieczeństwa zgłaszaj według [SECURITY.md](SECURITY.md).

## Licencja

Veless jest udostępniany na licencji GNU Affero General Public License v3.0 (AGPL-3.0), pełny tekst w pliku `LICENSE`.
