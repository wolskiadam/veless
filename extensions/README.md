# Wtyczki rozszerzające CRM (SDK)

CRM ma dwa rodzaje wtyczek:

| Rodzaj | Katalog | Do czego | Namespace |
|---|---|---|---|
| **Integracja** | `integrations/` | połączenie z innym systemem po API: sklep, marketplace, kurier, księgowość, SMS. Ma konta i klucze API. Opis: `integrations/README.md`. | `PasePlugin\Nazwa\` |
| **Rozszerzenie** | `extensions/` | nowa funkcja w samym CRM: kolumny listy zamówień, sekcje karty zamówienia, pakowanie, automatyzacje, statystyki, własne strony, wygląd. Bez kont i bez API. | `PaseExt\Nazwa\` |

Rozszerzenie podpina się pod **haki** rdzenia (`Pase\Plugin\Hooks`), jak wtyczki w WordPressie:

- **filtr** dostaje wartość (np. listę kolumn), zmienia ją i oddaje: `Hooks::addFilter('orders.columns', fn(array $cols) => ...)`,
- **akcja** reaguje na zdarzenie albo dopisuje HTML: `Hooks::addAction('packing.packed', fn(int $id, array $order) => ...)`.

Rdzeń nie zna żadnego rozszerzenia. Usunięcie katalogu usuwa funkcję.

> **Bezpieczeństwo:** rozszerzenie to zwykły kod PHP z pełnym dostępem do bazy i plików, tak samo jak integracja.
> Instaluj tylko rozszerzenia z zaufanego źródła. Na produkcji możesz wyłączyć wgrywanie ZIP z panelu
> (`PLUGIN_UPLOAD=0` w `.env`). Wtyczki wgrane z ZIP mają zapisany odcisk SHA-256 plików; strona Wtyczki
> ostrzega, gdy ich pliki zmienią się poza panelem (np. ktoś podrzuci plik PHP).
> Obce rozszerzenie sprawdź najpierw w sandboxie: `sh docker/sandbox/run.sh wtyczka.zip` (opis: `docker/sandbox/README.md`).

## Odporność na błędy

- Wyjątek w haku nie wywraca strony: jest zapisywany w logu (`Rozszerzenie <nazwa> - błąd w haku ...`), a reszta działa.
- Filtr musi oddać ten sam typ, który dostał (tablica → tablica). Inny typ jest odrzucany i logowany.
- Rozszerzenie z błędem w `register.php` albo `boot()` jest pomijane; pozostałe ładują się normalnie.
- Wyłączenie (znacznik `.disabled`, przycisk w panelu) od razu zdejmuje wszystkie haki rozszerzenia.

## Instalacja

1. **FTP**: wgraj katalog rozszerzenia do `extensions/`.
2. **Panel → Konfiguracja → Wtyczki** (tylko administrator): wgraj ZIP. Instalator sam rozpoznaje rodzaj
   (klasa z `PaseExt\` w `register.php` = rozszerzenie) i kładzie paczkę w `extensions/`. Działają te same zabezpieczenia
   co przy integracjach: zip-slip, skan funkcji, kopia poprzedniej wersji, wycofanie wadliwej paczki.

Na stronie Wtyczki rozszerzenia mają osobną tabelę: włącz/wyłącz, usuń i **⚙ Ustawienia** (formularz z pól manifestu).

## Struktura

```
extensions/
  mojafunkcja/
    register.php            <- zwraca FQCN klasy
    MojafunkcjaExtension.php
    assets/packing.js       <- opcjonalnie: skrypty/style
```

`register.php` (tylko `return`, bez deklaracji klas):
```php
<?php
return \PaseExt\Mojafunkcja\MojafunkcjaExtension::class;
```

Klasa:
```php
<?php
namespace PaseExt\Mojafunkcja;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;

final class MojafunkcjaExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'mojafunkcja',          // = nazwa katalogu, [a-z0-9_]
            name: 'Moja funkcja',
            version: '1.0.0',
            author: 'Ja',
            description: 'Co robi.',
            icon: '🧩',
            fields: [                     // ustawienia w panelu (opcjonalnie), format jak w integracjach
                ['key' => 'kolor', 'label' => 'Kolor', 'type' => 'select', 'options' => ['red' => 'Czerwony', 'blue' => 'Niebieski']],
            ],
        );
    }

    public function boot(): void
    {
        Hooks::addFilter('orders.columns', function (array $cols): array {
            $cols['waga'] = ['label' => 'Waga', 'cell' => fn(array $row): string => '1,2 kg'];
            return $cols;
        });
    }
}
```

`AbstractExtension` daje: `$this->setting('kolor', 'red')` (ustawienia z panelu), `$this->path('assets/x.js')`
(plik w katalogu rozszerzenia), `$this->pdo()` (baza). `boot()` tylko rejestruje haki; ciężką pracę (kwerendy)
rób w callbackach, bo `boot()` działa przy każdym żądaniu.

Gotowy, działający przykład używający każdego haka: **`examples/extensions/przyklad/`** (notatki wewnętrzne do zamówień).
Skopiuj go do `extensions/przyklad/`, żeby zobaczyć efekt.

## Katalog haków

Priorytet (trzeci argument `addFilter`/`addAction`, domyślnie 10): mniejszy działa wcześniej.

### Panel (każda strona)

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `admin.head` | akcja (HTML) | `string $pageKey` | wypisz HTML do `<head>` (np. `<style>`) |
| `admin.footer` | akcja (HTML) | `string $pageKey` | wypisz HTML przed `</body>` (np. `<script>`) |
| `admin.menu` | filtr | `array $groups, bool $canEdit, bool $isAdmin` | dodaj, usuń albo przestaw pozycje menu |
| `Hooks::addAsset('admin', $plik)` | — | plik `.css` / `.js` | wstawia plik na każdą stronę panelu |

### Własne strony: `Hooks::addPage($id, [...])`

```php
Hooks::addPage('raport', [
    'title'  => 'Mój raport',
    'group'  => 'orders',      // orders, catalog, marketplace, config, system (domyślnie)
    'role'   => 'editor',      // viewer, editor (domyślnie), admin
    'menu'   => true,          // false = bez pozycji w menu (np. zapis formularza)
    'raw'    => false,         // true = bez nagłówka panelu (np. odpowiedź JSON)
    'render' => function (PDO $pdo, array $ctx): string { return '<div class="card">...</div>'; },
]);
```

Adres: `ext.php?page=<rozszerzenie>.<id>` (pomocniczo `Hooks::pageUrl('mojafunkcja.raport')`). Rdzeń pilnuje logowania,
roli i tokenu CSRF przy POST (pole `csrf` = `$ctx['csrf']`). `$ctx`: `page`, `url`, `csrf`, `post`, `canEdit`, `isAdmin`,
`userId`, `user`. Strona może przekierować (`header('Location: ...'); exit;`) i ustawić komunikat `flash('...')`.

### Lista zamówień

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `orders.columns` | filtr | `array $columns, string $view` | układ kolumn: przestaw, usuń, dodaj |
| `orders.rows` | akcja | `array $rows` | cała strona wierszy przed rysowaniem (pobierz dane jedną kwerendą) |
| `orders.row_class` | filtr | `string $class, array $row` | klasy CSS wiersza |
| `orders.indicators` | filtr | `array $indicators, array $row` | ikony przy statusie: zmień stan albo dodaj własną |
| `orders.actionbar` | akcja (HTML) | `string $view` | przyciski na pasku akcji (zaznaczone: `.rowcheck:checked`) |

Kolumny rdzenia mają klucze `number, source, status, client, email, amount, delivery, date, actions`. Kolejność tablicy
to kolejność kolumn. Własna kolumna: `['label' => 'Nagłówek', 'cell' => fn(array $row): string /* HTML */, 'class' => '']`.
`$row` to wiersz `woo_orders` (m.in. `woo_order_id`, `pase_status`, `total`, `payload` jako JSON).
Pamiętaj o `htmlspecialchars()` dla danych z zamówienia.

Ikona: `['state' => 'ok|warn|bad|info|transit|partial', 'label' => 'Opis', 'literal' => true,
'icon' => '<path d="..."/>' /* SVG 24x24 */, 'href' => 'opcjonalny link']`. Kolor zmienisz CSS-em:
`.order-indicator[data-kind="moja_ikona"] { color: ... }`.

### Karta zamówienia

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `order_view.sections` | filtr | `array $sections, array $row, array $order` | dodaj sekcje (karty) |

Sekcja: `['id' => 'notatka', 'title' => 'Tytuł', 'col' => 'left'|'right', 'render' => fn(array $row, array $order): string]`.
Sekcje rozszerzeń przeciąga się i zapamiętuje jak sekcje rdzenia. Formularz w sekcji wysyłaj na własną stronę
(`Hooks::addPage(..., ['menu' => false])`) z polem `csrf`, a ta przekierowuje z powrotem do `order_view.php?id=...`.

### Automatyzacje

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `automation.actions` | filtr | `array $actions` | nowe akcje w edytorze reguł |
| `automation.events` | filtr | `array $events` | nowe zdarzenia w edytorze (wyzwalasz je sam, patrz niżej) |
| `automation.event` | akcja | `string $event, int $orderId, array $payload` | każde zdarzenie zamówienia, także bez reguł |

Akcja:
```php
$actions['x_moja_akcja'] = [
    'label'   => 'Zrób coś',
    'params'  => ['tekst' => 'Treść'],             // najwyżej jeden parametr; [] = bez parametru
    'options' => [['a', 'Opcja A'], ['b', 'Opcja B']], // opcjonalnie: lista wyboru zamiast pola tekstowego
    'hint'    => 'Podpowiedź pod polem',
    'run'     => function (int $orderId, array $params, array $payload, PDO $pdo): void { ... },
];
```
Klucze akcji i zdarzeń: `[a-z0-9_.]`, nie mogą nadpisać rdzenia (zalecany prefiks `x_`).
Własne zdarzenie wyzwalasz przez silnik reguł:
`(new \Pase\Automation\RuleEngine($pdo, new \Pase\Repository\AutomationRuleRepository($pdo)))->dispatch('x_zdarzenie', $orderId, $payload);`

### Asystent pakowania (panel i telefon)

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `packing.order` | filtr | `array $order, array $ctx` | dane zamówienia dla ekranu (dopisz swoje pod `$order['ext']['twoje']`) |
| `packing.packed` | akcja | `int $id, array $order, array $ctx` | po „Spakowano” |
| `packing.problem` | akcja | `int $id, array $order, array $ctx` | po „Nie spakowano” |
| `Hooks::addPackingAction($nazwa, ['run' => fn(int $id, array $in, array $ctx): array, 'write' => true])` | — | | akcja serwera wołana z JS |
| `Hooks::addAsset('packing', $plik)` | — | `.js` / `.css` | skrypt/styl ekranu pakowania |

`$ctx`: `user_id`, `user_name`, `can_edit`, `is_admin`, `panel` (false = telefon). Akcja z `'write' => true` wymaga
uprawnienia do edycji pakowania. `RuntimeException` z `run` trafia do użytkownika jako komunikat.

Skrypt pakowania używa `window.PackExt` (ładuje się po `packing.js`):
```js
PackExt.on('order', (o) => {             // po każdym narysowaniu; o = null, gdy nic nie jest otwarte
    if (!o) return;
    PackExt.panel.append(PackExt.el('div', { class: 'pk-note', text: o.ext.twoje.cos }));
    PackExt.actions.append(PackExt.el('button', { class: 'pk-btn ghost', text: 'Mój przycisk', onclick: async () => {
        const r = await PackExt.api('moja_akcja', { id: o.id });   // -> Hooks::addPackingAction('moja_akcja', ...)
        PackExt.toast(r.ok ? 'Gotowe' : r.error, r.ok ? 'ok' : 'bad');
        PackExt.reload();
    } }));
});
PackExt.on('item', (item, o) => {});     // po zmianie liczby sztuk pozycji
PackExt.on('packed', (o) => {});
PackExt.on('problem', (o) => {});
```
`PackExt.panel` (pod pozycjami) i `PackExt.actions` (obok „Spakowano”) są czyszczone przed każdym rysowaniem.
Inne: `PackExt.cfg` (m.in. `mode`, `canEdit`), `PackExt.current()`, `PackExt.beep(ok)`.

### Statystyki

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `statistics.cards` | filtr | `array $cards, array $ctx, PDO $pdo` | kafelki pod statystykami |

Kafelek: `['title' => 'Tytuł', 'html' => '...']` albo `['title' => ..., 'render' => fn(array $ctx, PDO $pdo): string]`.
`$ctx` to filtry strony: `from`, `to` (RRRR-MM-DD), `source`, `status`, `currency`.

### Oferta Allegro (formularz i Zarządzanie ofertami)

| Hak | Rodzaj | Argumenty | Co robi |
|---|---|---|---|
| `allegro_offer.description_tools` | akcja (HTML) | `array $ctx` | przyciski/skrypty nad edytorem opisu w formularzu oferty (np. własny generator opisu) |
| `allegro_offer.ext_data` | filtr | `array $ext, ?array $posted, array $product, array $saved` | dane rozszerzeń zapisywane razem z ofertą |
| `allegro_offers.operations` | akcja (HTML) | `array $ctx` | przyciski w menu OPERACJE na liście ofert |

`$ctx` formularza: `product_id`, `product` (wiersz `products`), `form` (formularz), `saved` (zapisana oferta), `images`, `categories`, `csrf`.
`$ctx` listy ofert: `csrf`. Zaznaczone oferty to `.al-sel:checked` (wartość = ID oferty Allegro).

**Dane rozszerzenia przy ofercie.** Formularz trzyma je w `$form['ext']` (klucz = nazwa rozszerzenia) i zapisuje z ofertą.
Filtr `allegro_offer.ext_data` jest wołany przy otwarciu formularza (`$posted === null`, np. do przeniesienia starych danych
z `$saved`) i przy zapisie (`$posted` = to, co przyszło z przeglądarki). Rdzeń niczego z `$posted` nie przepisuje sam:
rozszerzenie bierze **tylko swój klucz** i oczyszcza go:

```php
Hooks::addFilter('allegro_offer.ext_data', function (array $ext, ?array $posted, array $product, array $saved): array {
    if ($posted !== null && array_key_exists('mojafunkcja', $posted)) {
        $ext['mojafunkcja'] = MojeDane::sanitize($posted['mojafunkcja']);
    }
    return $ext;
});
```

**Skrypt w formularzu** używa `window.OfferForm` (gotowe po załadowaniu strony, więc wołaj je np. po kliknięciu):
`productId`, `csrf()`, `photos()` (zdjęcia oferty i galerii produktu), `getSections()` / `setSections(sekcje)` (opis w formacie
Allegro: `[{items:[{type:'TEXT',content:'<p>..</p>'},{type:'IMAGE',url:'https://..'}]}]`), `hasDescription()`,
`getExt(klucz)` / `setExt(klucz, dane)` (dane rozszerzenia zapisywane z ofertą), `showDescription()`.
Wysłanie opisu do trwającej oferty: POST `offer_allegro.php?product=ID&ajax=push_desc` z polami `csrf`, `offer_id`,
`description_sections` (JSON) i opcjonalnie `ext_data` (JSON `{klucz: dane}`, przechodzi przez ten sam filtr).
Produkt dla oferty z listy: `offer_allegro.php?offer=ID&resolve=1` → `{ok, product_id, name}`.

Pliki rozszerzenia (np. fonty, grafiki, większe skrypty) podasz własną stroną `'raw' => true`, która odda plik
z katalogu rozszerzenia z właściwym `Content-Type` (tylko pliki z listy, bez ścieżek z `..`).

## Własne tabele

Rozszerzenie tworzy swoje tabele samo (`CREATE TABLE IF NOT EXISTS ...`), najlepiej z prefiksem `ext_<nazwa>_`.
Składnia ma działać w MySQL (produkcja) i SQLite (testy) - patrz `examples/extensions/przyklad/Notes.php`.

## Testy

`php tests/extensions.php` (haki, rejestr, instalator) i `php tests/extensions_http.php` (przykład na prawdziwych
stronach panelu; z Playwright także ekran pakowania w przeglądarce).
