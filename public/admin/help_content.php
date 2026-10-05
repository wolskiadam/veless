<?php
declare(strict_types=1);

/**
 * Treści Bazy wiedzy (help.php). Zwraca tablicę artykułów:
 *   ['slug' => ['cat'=>..., 'title'=>..., 'body'=>HTML], ...]
 * Treść wbudowana w kod — działa bez plików zewnętrznych i bazy.
 * Kolejność artykułów = kolejność w spisie. Kategorie grupują się w tej kolejności.
 */

return [
    // ===== Start =====
    'start' => [
        'cat' => 'Start',
        'title' => 'Czym jest Veless',
        'body' => <<<'HTML'
<p><strong>Veless</strong> to centralny system pośredniczący (mini-BaseLinker), który łączy Twój sklep,
marketplace, kuriera i księgowość w jednym miejscu. CRM jest <strong>źródłem prawdy</strong> dla
zamówień, statusów, stanów magazynowych i danych produktów.</p>
<p>Główne obszary:</p>
<ul>
    <li><strong>Zamówienia</strong> — pobierane z kanałów (np. WooCommerce) do wspólnej listy.</li>
    <li><strong>Magazyn</strong> — produkty, stany i ceny; CRM może być masterem.</li>
    <li><strong>Wysyłka</strong> — nadawanie przesyłek i etykiety (BLPaczka).</li>
    <li><strong>Automatyzacje</strong> — reguły „zdarzenie → akcje” (np. zmiana statusu, e-mail).</li>
    <li><strong>Komunikacja</strong> — wątek wiadomości z klientem i publiczna strona zamówienia.</li>
    <li><strong>Wtyczki</strong> — system rozszerzasz integracjami jak wtyczkami.</li>
</ul>
<p>Każda zakładka panelu odpowiada jednemu z tych obszarów. Poniższe artykuły opisują je po kolei.</p>
HTML,
    ],
    'first-steps' => [
        'cat' => 'Start',
        'title' => 'Pierwsze kroki (konfiguracja)',
        'body' => <<<'HTML'
<p>Zalecana kolejność uruchamiania systemu:</p>
<ol>
    <li><strong>Dodaj integrację sklepu</strong> — Konfiguracja → Integracje → WooCommerce
        (adres sklepu + klucze REST API). Kliknij „Testuj połączenie”.</li>
    <li><strong>Zaimportuj zamówienia</strong> — Zamówienia → Import.</li>
    <li><strong>Zaimportuj produkty</strong> — Magazyn → Import produktów.</li>
    <li><strong>Ustaw statusy</strong> — Konfiguracja → Statusy zamówień (kolory, własne statusy).</li>
    <li><strong>Skonfiguruj e-mail (SMTP)</strong> — Konfiguracja → E-mail; wyślij test.</li>
    <li><strong>Dodaj kuriera</strong> — Konfiguracja → Integracje → BLPaczka.</li>
    <li><strong>Zbuduj automatyzacje</strong> — Konfiguracja → Automatyzacje.</li>
</ol>
<p>Worker (zadania w tle) musi działać z crona co minutę — bez niego import i wysyłka stoją
w kolejce. Stan workera widzisz w System → Pulpit.</p>
HTML,
    ],

    // ===== Zamówienia =====
    'orders' => [
        'cat' => 'Zamówienia',
        'title' => 'Lista zamówień i statusy',
        'body' => <<<'HTML'
<p>Zamówienia ze wszystkich kanałów trafiają na jedną listę (Zamówienia). Każde dostaje własny
<strong>numer CRM</strong> niezależny od numeru w sklepie. Filtrujesz po statusie, dacie, kwocie,
kliencie.</p>
<p><strong>Zmiana statusu:</strong> zaznacz zamówienia i użyj 🚩 „Zmień status”, albo zmień status
w szczegółach. Jeśli w integracji włączono synchronizację, status wraca też do sklepu.</p>
<p><strong>Statusy</strong> definiujesz w Konfiguracja → Statusy zamówień: 5 systemowych
(edytujesz nazwę/kolor) + dowolne własne. Kolor pokazuje się jako „pigułka” na liście.</p>
<p><strong>Nowe wiadomości klientów:</strong> zamówienia z nieprzeczytaną wiadomością są
podświetlone i mają badge <code>✉</code>. Skrót <code>✉</code> w nawigacji prowadzi do filtra
„tylko z nowymi wiadomościami”.</p>
<p><strong>Panel boczny i statusy:</strong> po lewej widzisz statusy z licznikami — kliknięcie
filtruje listę do danego statusu. Na dole są <strong>📦 Archiwum</strong> i <strong>🗑 Kosz</strong>.</p>
<p><strong>Archiwum:</strong> zamówienia starsze niż <strong>3 miesiące</strong> (wg daty złożenia)
trafiają tam automatycznie (robi to worker). Znikają z głównej listy, ale możesz je przeglądać
i <strong>przywrócić</strong> do aktywnych.</p>
<p><strong>Kosz:</strong> „Do kosza” to usunięcie miękkie — zamówienie znika z list, ale można je
<strong>przywrócić</strong>. Dopiero w Koszu wykonujesz <strong>trwałe usunięcie</strong>
(pojedynczo lub „Opróżnij kosz”) — tego nie cofniesz. Akcje działają na zaznaczonych (masowo)
lub przyciskiem przy wierszu.</p>
HTML,
    ],
    'order-detail' => [
        'cat' => 'Zamówienia',
        'title' => 'Szczegóły zamówienia',
        'body' => <<<'HTML'
<p>Na stronie zamówienia masz sekcje (przeciągalne, układ zapamiętuje się per użytkownik):</p>
<ul>
    <li><strong>Status</strong> — zmiana + ewentualny sync do sklepu.</li>
    <li><strong>Produkty</strong> — pozycje zamówienia.</li>
    <li><strong>Przesyłki</strong> — nadanie paczki, etykiety (A4/termiczna/ZPL/EPL), anulowanie.</li>
    <li><strong>Klient + adresy</strong> — dane do faktury i dostawy.</li>
    <li><strong>Wiadomości z klientem</strong> — wątek + odpowiedź + link dla klienta.</li>
</ul>
<p>Numer CRM nadawany jest wg daty zamówienia. Link „Otwórz w WooCommerce” prowadzi do
zamówienia w sklepie źródłowym.</p>
HTML,
    ],

    // ===== Magazyn =====
    'warehouse' => [
        'cat' => 'Magazyn',
        'title' => 'Produkty, stany i ceny',
        'body' => <<<'HTML'
<p>Magazyn CRM to centralna baza produktów (po <strong>SKU</strong>). Import pobiera pełne dane:
nazwy, opisy, zdjęcia, kategorie, atrybuty, EAN, wymiary.</p>
<p><strong>CRM jako master:</strong> stany i ceny CRM (<code>pase_stock</code>/<code>pase_price</code>)
przy reimporcie <em>nie są</em> nadpisywane danymi ze sklepu. Kierunek synchronizacji ustawiasz
w integracji. Zmianę stanu w CRM worker wypycha do sklepu.</p>
<p><strong>Masowa edycja</strong> stanów/cen, <strong>usuwanie</strong> oraz <strong>podgląd produktu</strong>
(galeria, opisy, atrybuty) są dostępne na liście produktów.</p>
<p><strong>Niski stan:</strong> każdy produkt może mieć <em>minimalny stan</em> (podgląd produktu albo
„Minimum zaznaczonych” na liście). Produkty bez własnego minimum korzystają z progu domyślnego
(„⚙ Próg domyślny” przy filtrach, na start 5). Gdy stan spadnie do minimum, produkt pojawia się
w filtrze „Niski stan”, w dzwoneczku i jako ikona 📉 przy tytule strony. Nic nie jest zamawiane
ani zmieniane w ofertach automatycznie.</p>
<p><strong>Stan faktyczny</strong> (domyślnie wyłączony, włączasz w Konfiguracja → Synchronizacja → Magazyn): ile towaru naprawdę masz na półce (kolumna na liście produktów
i pole w podglądzie produktu). Jest tylko w CRM: nie idzie do sklepu ani na Allegro, więc „Stan (CRM)”
może być wyższy dla lepszej prezentacji ofert. Wpisany stan faktyczny schodzi przy każdej sprzedaży
(Allegro i sklep), wraca przy anulowaniu zamówienia w sklepie i przy przyjęciu zwrotu na stan.
Zamówienia złożone przed wpisaniem liczby są już w niej uwzględnione. Puste pole = jeszcze nie policzony
(nic go nie zmienia). Gdy jest wpisany, alerty niskiego stanu liczą z niego.</p>
HTML,
    ],
    'product-fields' => [
        'cat' => 'Magazyn',
        'title' => 'Własne pola i kategorie',
        'body' => <<<'HTML'
<p>W Magazyn → Pola produktów definiujesz <strong>własne pola</strong> (tekst / liczba / lista
wyboru). Wartości wypełniasz per produkt w podglądzie (zakładka „Własne pola”).</p>
<p>Pole możesz <strong>przypisać do kategorii</strong>: wtedy pokaże się tylko przy produktach z
tej kategorii. Pole bez kategorii jest globalne (widoczne wszędzie). Kategorie (z importu Woo)
przeglądasz w Magazyn → Kategorie, z liczbą produktów i przypisanymi polami.</p>
<p>Wartości pól własnych to dane CRM — reimport ze sklepu ich nie kasuje.</p>
HTML,
    ],
    'offers' => [
        'cat' => 'Magazyn',
        'title' => 'Wystawianie na marketplace (Allegro)',
        'body' => <<<'HTML'
<p>W podglądzie produktu (Magazyn → Produkty → Podgląd) zakładka <strong>Marketplace</strong> pozwala
przygotować ofertę na kanałach sprzedaży. Allegro jest aktywne; kolejne kanały (eBay, Amazon) są
przygotowane jako miejsca („wkrótce").</p>
<p><strong>Przygotuj ofertę Allegro</strong> otwiera formularz wstępnie wypełniony danymi z magazynu
(tytuł = nazwa, opis, cena = cena CRM, stan = stan CRM, zdjęcia z galerii, kategoria, EAN). Pola
możesz dopracować, a po prawej widzisz <strong>podgląd na żywo „jak na Allegro"</strong> (mockup karty
oferty: zdjęcie, tytuł, cena, stan, opis). Zapis tworzy <strong>szablon oferty</strong> (tabela
<code>offer_templates</code>, jeden per produkt+kanał).</p>
<p><strong>Szablony opisu Allegro:</strong> budujesz opis w stylu Allegro z <strong>modułów Tekst i
Grafika</strong> (sekcje zgodne z formatem <code>description.sections[].items[]</code> z API Allegro).
W module tekstowym wstawiasz <strong>tagi z danymi produktu</strong> (panel „Tagi produktu" — kliknięcie
wstawia np. <code>[nazwa]</code>, <code>[cena]</code>, <code>[ean]</code>, <code>[sku]</code>,
<code>[kategoria]</code>, wymiary). Przy przygotowaniu oferty tagi zamieniają się na realne dane
produktu. Każdy szablon <strong>przypisujesz do kategorii</strong> produktów (pusty = domyślny). Przy
przygotowaniu oferty opis wypełnia się automatycznie z szablonu pasującego do kategorii produktu —
możesz go dopracować per oferta. Szablony opisu marketplace zbiera zakładka
<strong>Konfiguracja → Szablony</strong> (hub) — jest rozszerzalna: nowa wtyczka marketplace
deklarująca zdolność dostarczania szablonów (<code>ProvidesTemplates</code>) pojawi się tam
automatycznie. (Szablony e-mail mają osobną zakładkę — Konfiguracja → Szablony e-mail.)</p>
<p><strong>Uwaga:</strong> na tym etapie zapisujemy szablon w CRM. Realna publikacja do API Allegro
to kolejny krok — wymaga wyboru kategorii z drzewa Allegro, jej wymaganych parametrów, cennika dostaw
i wgrania zdjęć do Allegro.</p>
HTML,
    ],

    // ===== Wysyłka =====
    'shipping' => [
        'cat' => 'Wysyłka',
        'title' => 'Nadawanie przesyłek (BLPaczka)',
        'body' => <<<'HTML'
<p>Po dodaniu integracji <strong>BLPaczka</strong> (login + klucz API) w szczegółach zamówienia
pojawia się sekcja nadawania paczki: waga/wymiary, wybór kuriera (ulubieni jako szybkie przyciski
+ reszta z listy), dzień i godziny odbioru.</p>
<p><strong>Sposób nadania:</strong> przy każdej paczce wybierasz między <em>odbiorem przez kuriera</em>
(podajesz dzień i godziny odbioru) a <em>„sam dostarczę do przewoźnika"</em> — wtedy kurier nie
przyjeżdża po paczkę (do BLPaczka wysyłane jest <code>no_pickup</code>, pola odbioru są ukrywane).
Domyślny tryb ustawiasz w integracji BLPaczka („Domyślny tryb nadania"); można go zmienić per przesyłkę.</p>
<p>Nadanie tworzy przesyłkę i pobiera opłatę formą płatności ustawioną w integracji BLPaczka
(„Forma płatności za nadanie”, domyślnie <strong>Skarbonka (prepaid)</strong>). Pod przyciskiem „Nadaj paczkę”
widać saldo skarbonki, środowisko (Produkcja/Sandbox) i formę płatności - dokładnie to, co widzi BLPaczka.
Komunikat „Niewystarczająca kwota na skarbonce” oznacza, że na skarbonce tego konta jest mniej niż koszt nadania:
doładuj skarbonkę w panelu BLPaczka albo wybierz płatność odroczoną, jeśli masz ją w umowie.
Etykietę drukujesz w formacie A4, termicznym (A6), ZPL lub EPL (Zebra). Przesyłkę można
<strong>anulować</strong> — operacja nieodwracalna.</p>
<p>Domyślny format etykiety, tryb nadania i ulubionych kurierów ustawiasz w integracji BLPaczka oraz w
Konfiguracja → Drukowanie.</p>
HTML,
    ],

    // ===== Automatyzacje =====
    'automations' => [
        'cat' => 'Automatyzacje',
        'title' => 'Reguły: zdarzenie → akcje',
        'body' => <<<'HTML'
<p>Automatyzacja to <strong>zdarzenie + warunki + lista akcji</strong> (model BaseLinker).
Edytujesz ją w wierszu listy: po lewej zdarzenie i warunki, w środku ponumerowane akcje.</p>
<p><strong>Zdarzenia:</strong> Pobrano zamówienie, Ustawiono status, Zamówienie zostało opłacone
(raz, gdy zamówienie stanie się opłacone — w sklepie, wpłatą w CRM albo przez PayU), Utworzono przesyłkę,
Zmieniono status przesyłki u kuriera, Wystawiono fakturę, Wystawiono paragon.</p>
<p><strong>Warunki</strong> — przy regule wybierasz, czy muszą być spełnione <em>wszystkie</em> (i), czy <em>wystarczy jeden</em> (lub): status CRM i kanału, płatność (opłacone, częściowo, nieopłacone,
za pobraniem…), zamówienie wirtualne, klient chce fakturę (lub podał NIP), dokument sprzedaży (faktura /
paragon / brak), suma, liczba sztuk, źródło, metoda płatności, metoda dostawy, kraj dostawy, e-mail klienta,
SKU, nazwa produktu, przesyłka utworzona, status przesyłki. Brak warunków = zawsze.</p>
<p><strong>Akcje:</strong> ustaw status, wyślij e-mail, wyślij SMS, wystaw fakturę lub paragon (faktura, gdy
klient o nią prosi, inaczej paragon), wystaw fakturę, stwórz paragon, przekaż zamówienie do sklepu,
oznacz jako opłacone, oznacz jako wirtualne. Automatyzacja nigdy nie wystawi drugiej faktury ani paragonu
dla zamówienia, które ma już dokument sprzedaży.</p>
<p>Przykład: <em>Pobrano zamówienie</em> + Płatność jest Opłacone + Zamówienie wirtualne jest Tak →
1. Wystaw fakturę lub paragon, 2. Ustaw status Zrealizowane. Zamówienia opłacane później łapie ta sama reguła
ze zdarzeniem <em>Zamówienie zostało opłacone</em>.</p>
<p>Na liście każda reguła jest opisana zdaniem: „Gdy pobrano zamówienie i spełnione są wszystkie warunki: … → 1. … 2. …”,
a w edytorze pod regułą widać podgląd tego zdania.</p>
<p>Reguły wykonuje worker wg priorytetu (mniejsza liczba = wcześniej).</p>
HTML,
    ],
    'email' => [
        'cat' => 'Automatyzacje',
        'title' => 'E-mail i szablony',
        'body' => <<<'HTML'
<p>Wysyłka odbywa się przez <strong>SMTP</strong> (Konfiguracja → E-mail): host, port, szyfrowanie,
login, nadawca. Przycisk „Zapisz i wyślij test” sprawdza konfigurację.</p>
<p><strong>Szablony</strong> (Konfiguracja → Szablony e-mail) to treść HTML z tematem i
zmiennymi <code>{{...}}</code>: <code>customer_name</code>, <code>order_number</code>,
<code>pase_number</code>, <code>total</code>, <code>currency</code>, <code>status</code>,
<code>shop_name</code> i in. Wersję tekstową system dokłada automatycznie.</p>
<p><strong>Edytor</strong> ma trzy zakładki: <em>Wizualnie</em> (WYSIWYG — formatowanie bez
znajomości HTML), <em>HTML</em> (edycja źródła) i <em>Podgląd</em> (na żywo, z przykładowymi
danymi w miejsce zmiennych). Zmienne wstawiasz klikając w „pigułki" pod treścią — trafiają do
edytora w miejscu kursora.</p>
<p>Szablon wybierasz w regule automatyzacji (akcja „Wyślij e-mail”). Adres klienta brany jest
z zamówienia.</p>
HTML,
    ],

    // ===== Klient =====
    'client-page' => [
        'cat' => 'Strona klienta',
        'title' => 'Strona zamówienia i wiadomości',
        'body' => <<<'HTML'
<p>Każde zamówienie ma <strong>publiczną stronę dla klienta</strong> (link z tokenem). Klient widzi
status (pasek kroków), produkty, adres dostawy, śledzenie przesyłki oraz <strong>wątek
wiadomości</strong> i formularz odpowiedzi z załącznikiem.</p>
<p><strong>Które sekcje widzi klient i w jakiej kolejności:</strong> w Konfiguracja → Strona klienta
zaznaczasz, które boxy mają się wyświetlać (status zamówienia, status dostawy, produkty, adres dostawy,
śledzenie przesyłki, wiadomości), a <strong>przeciągając je za uchwyt ⠿</strong> ustawiasz kolejność na
stronie klienta. Wyłączony box znika; kolejność zapamiętywana jest w ustawieniu <code>CLIENT_BOX_ORDER</code>.
Nagłówek zamówienia (numer, data, status) jest zawsze na górze. Box „Śledzenie przesyłki" wyświetla się
razem z „Adresem dostawy", więc jego pozycję wyznacza pozycja adresu dostawy.</p>
<p><strong>Status dostawy</strong> to <strong>mapa trasy przesyłki</strong> (Leaflet / OpenStreetMap):
pinezka nadawcy (z danych integracji kurierskiej BLPaczka) i pinezka adresu dostawy, połączone linią,
z czarnym znacznikiem postępu na trasie wg etapu (Przyjęte → W realizacji → Nadana → Doręczona). Etap
liczony jest z realnych danych (status CRM + czy nadano przesyłkę), a znacznik na linii to interpolacja,
<strong>nie</strong> położenie GPS kuriera. Współrzędne pochodzą z geokodowania kodu pocztowego/miasta
(usługa Nominatim/OSM) i są cachowane w tabeli <code>geocode_cache</code> — adres dokładny nie jest
ujawniany, mapa działa na poziomie miejscowości. Wymaga, by serwer mógł łączyć się z
<code>nominatim.openstreetmap.org</code> oraz CDN <code>unpkg.com</code>/<code>cartocdn.com</code>.
Anulowane zamówienie pokazuje status „Anulowana" (bez mapy). Mapa ładuje się asynchronicznie przez
<code>geocode.php?token=…</code> (ta sama bramka weryfikacji co strona klienta).</p>
<p><strong>Pod mapą</strong> jest panel „Informacje" o przesyłce: aktualny status, numer zamówienia,
data sprzedaży, rodzaj przesyłki (kurier + koszt z nadanej etykiety), pasek postępu (% wg etapu) oraz
numer listu przewozowego z przyciskiem <strong>„Śledzenie paczki"</strong>. Przycisk prowadzi do strony
śledzenia właściwego przewoźnika (InPost/Paczkomaty, DPD, GLS, DHL, Poczta Polska — rozpoznawane po
kodzie kuriera; dla pozostałych uniwersalna wyszukiwarka). Tam klient zobaczy <strong>realny</strong>
status u kuriera. % i status w panelu są spójne z mapą i wynikają z danych CRM, nie z odpytywania API
przewoźnika.</p>
<p><strong>Weryfikacja:</strong> po wejściu klient wpisuje jedną daną z zamówienia (e-mail lub
numer telefonu) — system sam rozpoznaje, którą podał. Funkcję można włączyć/wyłączyć w
Konfiguracja → Strona klienta. Limit 5 prób / 15 min chroni przed zgadywaniem.</p>
<p>Odpowiedź obsługi wysyła e-mail do klienta; nowa wiadomość klienta powiadamia administratora.
Link dla klienta kopiujesz w sekcji „Wiadomości z klientem” w szczegółach zamówienia.</p>
HTML,
    ],

    // ===== Wtyczki =====
    'plugins' => [
        'cat' => 'Wtyczki',
        'title' => 'Integracje jako wtyczki',
        'body' => <<<'HTML'
<p>Integracje działają jak wtyczki: każda to samodzielny katalog wykrywany automatycznie.
Dodanie integracji nie wymaga zmian w rdzeniu. Wtyczka jest samowystarczalna — cała logika API
integracji mieszka w jej katalogu, a rdzeń wywołuje ją tylko przez kontrakt (rejestr), więc
usunięcie katalogu realnie usuwa integrację.</p>
<p><strong>Instalacja:</strong> przez FTP (katalog do <code>integrations/</code>) albo z panelu
Konfiguracja → Wtyczki (wgranie ZIP — tylko administrator). Instalator waliduje strukturę i
manifest, skanuje niebezpieczne funkcje i robi kopię zapasową.</p>
<p>Na stronie Wtyczki włączasz/wyłączasz i usuwasz integracje oraz widzisz ich wersje i
zdolności (źródło zamówień / magazyn / kurier / faktury).</p>
<p><strong>Dodawanie integracji:</strong> Konfiguracja → Integracje → „+ Dodaj integrację" otwiera
galerię kafelków pogrupowanych w kategorie (Marketplace, Sklepy, Kurierzy, Księgowość) z wyszukiwarką.
Kategoria każdej wtyczki bierze się z manifestu (pole <code>category</code>) lub jest wyprowadzana
z jej zdolności. Nowa zainstalowana wtyczka pojawia się w galerii automatycznie.</p>
<p><strong>Pisanie własnych wtyczek:</strong> patrz artykuł „API wtyczek (dla programistów)”.</p>
HTML,
    ],
    'plugin-api' => [
        'cat' => 'Wtyczki',
        'title' => 'API wtyczek (dla programistów)',
        'body' => <<<'HTML'
<p>Integracja to samodzielny katalog w <code>integrations/&lt;vendor&gt;/</code> wykrywany
automatycznie. Dodanie wtyczki nie wymaga zmian w rdzeniu.</p>

<h3>Struktura katalogu</h3>
<pre><code>integrations/
  mojkanal/
    register.php          &larr; zwraca FQCN klasy wtyczki
    MojkanalPlugin.php     &larr; klasa wtyczki</code></pre>
<p>Plik <code>register.php</code>:</p>
<pre><code>&lt;?php
return \PasePlugin\Mojkanal\MojkanalPlugin::class;</code></pre>
<p class="kb-note">Plik klasy nie może nazywać się <code>plugin.php</code> (kolizja z
<code>register.php</code> na systemach case-insensitive). Użyj nazwy klasy, np.
<code>MojkanalPlugin.php</code>.</p>

<h3>Autoloading</h3>
<p>Namespace <code>PasePlugin\&lt;Vendor&gt;\...</code> mapuje się na
<code>integrations/&lt;vendor&gt;/...</code> (pierwszy segment = nazwa katalogu, małymi
literami). Bez Composera — ładuje autoloader rdzenia. Przykład:
<code>PasePlugin\Mojkanal\MojkanalPlugin</code> &rarr;
<code>integrations/mojkanal/MojkanalPlugin.php</code>.</p>

<h3>Bazowy kontrakt — IntegrationPlugin</h3>
<p>Każda wtyczka implementuje <code>Pase\Plugin\Contract\IntegrationPlugin</code>:</p>
<table>
    <tr><th>Metoda</th><th>Opis</th></tr>
    <tr><td><code>manifest(): PluginManifest</code></td><td>Metadane + deklaracja pól konfiguracji. Bez efektów ubocznych.</td></tr>
    <tr><td><code>withConfig(array $config): static</code></td><td>Zwraca instancję związaną z konfiguracją konta.</td></tr>
    <tr><td><code>testConnection(): array</code></td><td>Test połączenia. Zwraca <code>['ok'=&gt;bool, 'message'=&gt;string]</code>.</td></tr>
</table>
<p>Najprościej rozszerzyć <code>Pase\Plugin\AbstractPlugin</code> — daje gotowe
<code>withConfig()</code> oraz helper <code>cfg('klucz', $default)</code> do odczytu konfiguracji.</p>

<h3>Manifest (PluginManifest)</h3>
<p>Parametry konstruktora:</p>
<table>
    <tr><th>Pole</th><th>Typ / domyślne</th><th>Znaczenie</th></tr>
    <tr><td><code>type</code></td><td>string</td><td>unikalny klucz typu, <code>[a-z0-9_]</code></td></tr>
    <tr><td><code>name</code></td><td>string</td><td>nazwa wyświetlana</td></tr>
    <tr><td><code>version</code></td><td>'1.0.0'</td><td>wersja (semver)</td></tr>
    <tr><td><code>author</code></td><td>''</td><td>autor</td></tr>
    <tr><td><code>capabilities</code></td><td>[]</td><td>lista zdolności (patrz niżej)</td></tr>
    <tr><td><code>fields</code></td><td>[]</td><td>pola formularza konfiguracji</td></tr>
    <tr><td><code>color</code></td><td>'#667eea'</td><td>kolor akcentu w UI</td></tr>
    <tr><td><code>icon</code></td><td>'🔌'</td><td>ikona w UI</td></tr>
    <tr><td><code>multiple</code></td><td>true</td><td>czy można dodać wiele kont tego typu</td></tr>
    <tr><td><code>description</code></td><td>''</td><td>krótki opis</td></tr>
</table>

<h3>Pola konfiguracji (fields)</h3>
<p>Sterują formularzem konta w panelu — wtyczka sama deklaruje, jakich danych potrzebuje.
Klucze pola: <code>key</code>, <code>label</code>, <code>type</code>
(<code>text|password|url|select|checkbox|textarea</code>), <code>required</code>,
<code>secret</code> (maskuj i nie nadpisuj pustym), <code>options</code> (dla
<code>select</code>), <code>help</code>, <code>default</code>.</p>

<h3>Zdolności (capabilities)</h3>
<p>Wtyczka deklaruje zdolności w manifeście i implementuje odpowiadające im interfejsy z
<code>Pase\Plugin\Contract\*</code>. Jedna wtyczka może łączyć kilka.</p>
<table>
    <tr><th>Stała</th><th>Interfejs</th><th>Metody</th></tr>
    <tr><td><code>Capability::ORDER_SOURCE</code></td><td>OrderSource</td>
        <td><code>fetchOrders($page,$perPage=50,$after=null): array</code><br>
            <code>pushOrderStatus($externalOrderId,$paseStatus): bool</code></td></tr>
    <tr><td><code>Capability::WAREHOUSE</code></td><td>Warehouse</td>
        <td><code>fetchProducts($page,$perPage=50): array</code><br>
            <code>pushStock($externalId,$stock): bool</code><br>
            <code>fetchStock($externalId): ?int</code></td></tr>
    <tr><td><code>Capability::COURIER</code></td><td>Courier</td>
        <td><code>listServices($parcel): array</code><br>
            <code>quote($params): array</code><br>
            <code>createShipment($shipment): array</code><br>
            <code>getLabel($externalId,$format='A4'): array</code><br>
            <code>cancelShipment($externalId): array</code></td></tr>
    <tr><td><code>Capability::INVOICING</code></td><td>Invoicing</td>
        <td><code>issueDocument($contractor,$positions,$type='normal'): array</code></td></tr>
</table>

<h3>Przykład minimalnej wtyczki</h3>
<pre><code>&lt;?php
declare(strict_types=1);
namespace PasePlugin\Mojkanal;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\OrderSource;
use Pase\Plugin\PluginManifest;

final class MojkanalPlugin extends AbstractPlugin implements OrderSource
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'mojkanal',
            name: 'Mój Kanał',
            version: '1.0.0',
            author: 'Twoja Firma',
            capabilities: [Capability::ORDER_SOURCE],
            fields: [
                ['key' =&gt; 'api_url', 'label' =&gt; 'URL API', 'type' =&gt; 'url', 'required' =&gt; true],
                ['key' =&gt; 'token',   'label' =&gt; 'Token',   'type' =&gt; 'password', 'secret' =&gt; true],
            ],
            icon: '🛒',
        );
    }

    public function testConnection(): array
    {
        $url = $this-&gt;cfg('api_url');
        // ... sprawdź połączenie ...
        return ['ok' =&gt; true, 'message' =&gt; 'Połączenie OK'];
    }

    public function fetchOrders(int $page, int $perPage = 50, ?string $after = null): array
    {
        return []; // zwróć znormalizowane zamówienia; [] = koniec stron
    }

    public function pushOrderStatus(string $externalOrderId, string $paseStatus): bool
    {
        return true;
    }
}</code></pre>

<h3>Cykl życia i wskazówki</h3>
<ul>
    <li>Rejestr (<code>Pase\Plugin\PluginRegistry</code>) tworzy instancję, czyta
        <code>manifest()</code>, a dla konkretnego konta woła <code>withConfig($config)</code>.</li>
    <li><code>manifest()</code> bez efektów ubocznych — ma tylko opisywać.</li>
    <li>Sekrety oznacz <code>'secret' =&gt; true</code> — panel ich nie pokaże i nie skasuje pustym polem.</li>
    <li>Pobieranie zamówień/produktów powinno być idempotentne (bezpieczne przy powtórzeniu).</li>
    <li>Waliduj odpowiedzi API; rzucaj wyjątki z czytelnym komunikatem.</li>
</ul>

<p class="kb-note">Wtyczka to pełnoprawny kod PHP z dostępem do bazy, kluczy i plików.
Instaluj wyłącznie wtyczki z zaufanego źródła.</p>
HTML,
    ],

    // ===== System =====
    // ===== Instalacja =====
    'install-offline' => [
        'cat' => 'Instalacja',
        'title' => 'Instalacja offline (na własnym komputerze)',
        'body' => <<<'HTML'
<p>CRM można zainstalować na zwykłym komputerze z Windows, macOS albo Linuksem, bez hostingu.
Działa wtedy pod adresem <code>http://localhost:8081/admin/</code>, a wszystkie dane leżą w folderze CRM
na tym komputerze. Pod spodem działa <strong>Docker</strong>: darmowy program, który uruchamia bazę danych
i panel w jednym pakiecie.</p>

<h3>Zanim zaczniesz: czym to się różni od wersji online</h3>
<ul>
    <li><strong>Komputer musi być włączony</strong>, żeby spływały zamówienia i szły maile. Gdy jest wyłączony,
        zamówienia czekają w Allegro i w sklepie i ściągają się po włączeniu.</li>
    <li>CRM sam sprawdza sklepy co minutę. Webhooki nie są potrzebne.</li>
    <li>Pakowanie z telefonu działa tylko w tej samej sieci Wi-Fi (opis niżej).</li>
    <li><strong>Kopie zapasowe robisz sam</strong>: folder CRM to Twoje dane.</li>
</ul>

<h3>1. Zainstaluj Docker Desktop</h3>
<ol>
    <li>Pobierz Docker Desktop ze strony <code>docker.com/products/docker-desktop</code> i zainstaluj
        (Windows: zgódź się na włączenie „WSL 2”, jeśli instalator o to zapyta, i uruchom komputer ponownie).</li>
    <li>Uruchom Docker Desktop i poczekaj, aż na dole okna pojawi się zielony napis <em>Engine running</em>.</li>
    <li>W ustawieniach Dockera zaznacz <em>Start Docker Desktop when you sign in</em>, żeby CRM ruszał sam po włączeniu komputera.</li>
</ol>

<h3>2. Pobierz CRM</h3>
<ol>
    <li>Pobierz kod CRM jako ZIP (na GitHubie: <em>Code → Download ZIP</em>).</li>
    <li>Rozpakuj go do stałego miejsca, np. <code>C:\CRM</code> albo <code>Dokumenty/CRM</code>.
        Nie trzymaj go w folderze „Pobrane” ani na pulpicie, który się sprząta.</li>
</ol>

<h3>3. Uruchom instalator</h3>
<p><strong>Windows</strong>: otwórz folder CRM, kliknij w pasek adresu Eksploratora, wpisz <code>powershell</code>
i naciśnij Enter. W oknie, które się otworzy, wklej:</p>
<pre>powershell -ExecutionPolicy Bypass -File docker\install.ps1</pre>
<p><strong>macOS / Linux</strong>: otwórz Terminal, przejdź do folderu CRM (np. <code>cd ~/Documents/CRM</code>) i wpisz:</p>
<pre>sh docker/install.sh</pre>
<p>Za pierwszym razem trwa to kilka minut, bo Docker pobiera bazę danych i PHP. Na końcu instalator wypisze:</p>
<pre>Gotowe. Panel: http://localhost:8081/admin/
Login: admin   Hasło: (losowe hasło)</pre>
<p><strong>Zapisz to hasło.</strong> Otwórz adres w przeglądarce, zaloguj się i od razu zmień hasło
(menu konta → Bezpieczeństwo).</p>

<h3>4. Skonfiguruj CRM</h3>
<p>Dalej wszystko jak w wersji online (artykuł „Pierwsze kroki”): dodaj integracje sklepu, Allegro, kuriera
i e-mail, potem zaimportuj zamówienia. Dwie różnice:</p>
<ul>
    <li><strong>WooCommerce</strong>: nie dodawaj webhooka. Ekran integracji pokaże, że nie jest potrzebny.</li>
    <li><strong>Allegro</strong>: w aplikacji na <code>apps.developer.allegro.pl</code> wpisz adres przekierowania
        <code>http://localhost:8081/auth_allegro_callback.php</code>.</li>
</ul>
<p>Masz już CRM online? Zamiast konfigurować od zera, przenieś dane: <a href="help.php?a=handover">Przeniesienie danych online ⇄ komputer</a>.</p>

<h3>Codzienna obsługa</h3>
<ul>
    <li>CRM uruchamia się sam razem z Dockerem. Zakładka bramki w przeglądarce: <code>http://localhost:8081/admin/</code>.</li>
    <li>Wyłączenie: w Docker Desktop → <em>Containers</em> → <em>crm</em> → Stop (dane zostają). Włączenie: Start.</li>
    <li>Program do drukowania etykiet łączy się z adresem <code>http://localhost:8081</code>.</li>
</ul>

<h3>Pakowanie z telefonu</h3>
<p>Domyślnie panel jest widoczny tylko na tym komputerze. Żeby otworzyć go z telefonu w tej samej sieci Wi-Fi,
dopisz na końcu pliku <code>.env</code> w folderze CRM linię <code>CRM_BIND=0.0.0.0</code> i uruchom instalator jeszcze raz.
W telefonie wejdź na <code>http://ADRES-KOMPUTERA:8081/admin/</code> (adres IP komputera, np. <code>192.168.1.20</code>).
Nie wystawiaj tego portu do internetu.</p>

<h3>Kopie zapasowe</h3>
<ul>
    <li>Najprościej: System → Kopia zapasowa → pobierz kopię i trzymaj ją poza komputerem (pendrive, chmura).</li>
    <li>Albo zatrzymaj CRM i skopiuj cały folder (najważniejsze są <code>dane/</code>, <code>storage/</code> i <code>.env</code>).</li>
</ul>

<h3>Aktualizacja</h3>
<ol>
    <li>Pobierz nowy ZIP z CRM.</li>
    <li>Skopiuj jego zawartość do folderu CRM i zastąp pliki. <strong>Nie usuwaj</strong> <code>.env</code>,
        <code>dane/</code> ani <code>storage/</code>, bo to Twoje hasła i dane.</li>
    <li>Uruchom instalator jeszcze raz (krok 3). Zaktualizuje CRM bez utraty danych.</li>
</ol>

<h3>Gdy coś nie działa</h3>
<ul>
    <li><strong>„Brak Dockera”</strong>: Docker Desktop nie jest uruchomiony. Włącz go i poczekaj na <em>Engine running</em>.</li>
    <li><strong>„Panel nie wstał”</strong>: port 8081 może być zajęty. W pliku <code>.env</code> zmień <code>CRM_PORT=8081</code> na <code>CRM_PORT=8090</code>,
        a w liniach <code>APP_URL</code> i <code>ALLEGRO_REDIRECT_URI</code> zamień <code>8081</code> na <code>8090</code>.
        Uruchom instalator ponownie; panel będzie pod <code>http://localhost:8090/admin/</code>.</li>
    <li><strong>Zamówienia nie spływają</strong>: Pulpit pokazuje, kiedy ostatnio działał worker. Jeśli dawno,
        uruchom ponownie CRM w Docker Desktop. Podgląd błędów: w Docker Desktop kontener <em>worker</em> → <em>Logs</em>.</li>
    <li><strong>Zapomniane hasło</strong>: pierwsze hasło administratora jest w pliku <code>.env</code>
        (linia <code>ADMIN_DEFAULT_PASSWORD</code>), ale działa tylko do czasu jego zmiany w panelu.</li>
</ul>
HTML,
    ],
    'handover' => [
        'cat' => 'Instalacja',
        'title' => 'Przeniesienie danych online ⇄ komputer',
        'body' => <<<'HTML'
<p>Jeśli masz CRM online i CRM na komputerze, możesz przenosić pracę między nimi jednym guzikiem:
System → <a href="handover.php">Przeniesienie danych</a> (tylko administrator).</p>
<p><strong>Zawsze pracuje tylko jedna strona.</strong> Druga jest wstrzymana: ma czerwony pasek u góry,
nie da się w niej nic zmienić, nie pobiera zamówień i nie wysyła maili. Dzięki temu żadne zamówienie nie zostanie
obsłużone dwa razy.</p>

<h3>Z online na komputer</h3>
<ol>
    <li>W CRM <strong>online</strong>: Przeniesienie danych → <em>Wygeneruj kod połączenia</em>. Skopiuj kod
        (zaczyna się od <code>CRM1:</code>), bo pokazujemy go tylko raz.</li>
    <li>W CRM <strong>na komputerze</strong>: Przeniesienie danych → wklej kod → <em>Zapisz kod</em>.</li>
    <li>Na komputerze kliknij <strong>⬇️ Przenieś dane z online na ten komputer</strong>.</li>
    <li>Poczekaj, aż strona pokaże „Ten komputer przejął pracę” (zwykle kilka minut, strona odświeża się sama).</li>
    <li>Zaloguj się ponownie <strong>loginem i hasłem z online</strong>, bo konta przyszły razem z danymi.</li>
</ol>

<h3>Z komputera z powrotem na online</h3>
<ol>
    <li>Na komputerze: Przeniesienie danych → <strong>⬆️ Wyślij dane z tego komputera na online</strong>.</li>
    <li>Poczekaj na „Online przejął pracę”. Od tej chwili pracujesz w CRM online, a komputer jest wstrzymany.</li>
</ol>
<p>Oba guziki są na komputerze, bo online nie może sam połączyć się z Twoim komputerem.
Kod połączenia wystarczy wygenerować raz.</p>

<h3>Co się przenosi</h3>
<ul>
    <li>Cała baza: zamówienia, produkty, ustawienia, integracje, konta, szablony, kolejka zadań.</li>
    <li>Pliki: załączniki, dokumenty, grafiki do wydruków.</li>
    <li><strong>Nie</strong> przenoszą się: kod CRM, plik <code>.env</code>, adres panelu i częstotliwość pobierania zamówień
        (każda strona ma swoje) oraz wtyczki doinstalowane ręcznie (muszą być po obu stronach).</li>
</ul>
<p>Po przeniesieniu CRM sam dociąga ze sklepów zamówienia, które zmieniły się w trakcie.</p>

<h3>Ważne</h3>
<ul>
    <li>Dane po stronie, która przyjmuje pracę, są <strong>zastępowane</strong>, a nie łączone.</li>
    <li>Wysłać dane na online da się tylko wtedy, gdy online jest wstrzymany. Inaczej nadpisałbyś nowsze zamówienia.</li>
    <li>Guzik <em>Wznów pracę tutaj</em> jest awaryjny (np. zepsuty komputer). Zmiany zrobione po drugiej stronie wtedy przepadają.</li>
    <li>Wymagany działający cron workera na online, bo to on pakuje i wgrywa dane.</li>
</ul>
HTML,
    ],
    'worker' => [
        'cat' => 'System',
        'title' => 'Worker, kolejka i pulpit',
        'body' => <<<'HTML'
<p>Operacje w tle (import zamówień/produktów, sync stanów, wysyłka e-maili) idą przez
<strong>kolejkę zadań</strong>. Wykonuje je <strong>worker</strong> uruchamiany z crona co minutę.</p>
<p>W System → Pulpit widzisz liczniki i stan workera; w Zamówienia → Kolejka — zadania i ich
ponawianie. Jeśli import „stoi”, najpierw sprawdź, czy worker działa (cron).</p>
<p>Nieudane zadania są ponawiane z opóźnieniem (backoff). Trwale nieudane oznaczane są jako
<code>failed</code> — można je przejrzeć w kolejce.</p>
HTML,
    ],
    'sync' => [
        'cat' => 'System',
        'title' => 'Synchronizacja cykliczna',
        'body' => <<<'HTML'
<p>W <strong>Konfiguracja → Synchronizacja</strong> ustawiasz, co ile minut CRM wykonuje
poszczególne przepływy. <strong>0 = wyłączone.</strong> Realizuje je worker (cron co minutę):
przy każdym przebiegu sprawdza, którym przepływom minął interwał, i kolejkuje ich zadania.</p>
<ul>
    <li><strong>Pobieranie nowych zamówień</strong> — zaciąga zamówienia nowsze niż ostatnio
        pobrane (uzupełnia webhook, gdyby któryś nie dotarł).</li>
    <li><strong>Synchronizacja stanów</strong> — wypycha stany CRM → sklep (gdy CRM jest masterem).</li>
    <li><strong>Synchronizacja cen</strong> — wypycha ceny CRM → sklep (cena regularna).</li>
    <li><strong>Auto-import produktów</strong> — odświeża katalog produktów ze sklepu.</li>
</ul>
<p>Częstotliwość nie może być mniejsza niż rozdzielczość crona (1 min). Sugestie: zamówienia
co 5 min, stany/ceny co 15–30 min, produkty co 120 min. Webhook nadal pobiera nowe zamówienia
natychmiast — pobieranie cykliczne jest zabezpieczeniem.</p>
HTML,
    ],
    'i18n' => [
        'cat' => 'System',
        'title' => 'Języki (wielojęzyczność)',
        'body' => <<<'HTML'
<p>System ma lekką warstwę tłumaczeń (<code>src/Support/I18n.php</code>, skrót <code>t('klucz')</code>).
Słowniki to pliki <code>lang/&lt;kod&gt;.php</code> zwracające tablicę <code>['klucz' =&gt; 'tekst']</code>.
Język bazowy i zapasowy to <strong>polski</strong> — brakujący klucz spada na PL, a potem na sam klucz.</p>
<p><strong>Strona klienta i e-maile</strong> są wielojęzyczne. Język klienta wykrywany jest
automatycznie: z kraju zamówienia (np. GB/US → angielski) lub z ustawień przeglądarki; na stronie
zamówienia jest też przełącznik języka. E-mail powiadomienia o wiadomości wysyłany jest w języku
klienta.</p>
<p><strong>Dodanie języka:</strong> skopiuj <code>lang/pl.php</code> do np. <code>lang/en.php</code>,
przetłumacz wartości (klucze zostaw bez zmian), ustaw <code>'_name'</code> na nazwę języka. Pojawi się
automatycznie.</p>
<p><strong>Panel administracyjny</strong> ma już przełącznik języka (PL/EN obok obszaru użytkownika,
zapamiętany per zalogowany użytkownik). Przetłumaczone są: logowanie, nawigacja i lista zamówień
(wzorzec). Kolejne ekrany przekładamy stopniowo — każdy tekst zamieniamy na <code>t('klucz')</code>
i dodajemy klucz do słowników. Język ustawia <code>public/admin/auth.php</code> (sesja
<code>pase_lang</code>, przełącznik <code>?setlang=xx</code>).</p>
HTML,
    ],
    'security' => [
        'cat' => 'System',
        'title' => 'Bezpieczeństwo i dobre praktyki',
        'body' => <<<'HTML'
<ul>
    <li>Plik <code>.env</code> i katalog <code>storage/</code> nie mogą być dostępne z weba
        (chroni je <code>.htaccess</code>).</li>
    <li>Klucze API trzymaj w panelu (tabela ustawień), nie w publicznych plikach.</li>
    <li>Wtyczki instaluj tylko z zaufanego źródła — to pełnoprawny kod PHP.</li>
    <li>Role: <strong>admin</strong> (pełnia, w tym wtyczki), <strong>editor</strong> (edycja),
        <strong>viewer</strong> (podgląd).</li>
    <li>Produkcyjne nadawanie paczek to realne koszty (prepaid kuriera) — operacje wysyłki
        wymagają potwierdzenia.</li>
</ul>
HTML,
    ],
];
