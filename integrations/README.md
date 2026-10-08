# Wtyczki integracji CRM (SDK)

> CRM ma dwa rodzaje wtyczek. Ten plik opisuje **integracje** (połączenia z innymi systemami po API).
> Nowe funkcje w samym CRM (kolumny, sekcje karty zamówienia, pakowanie, automatyzacje, statystyki) robi się jako
> **rozszerzenia** w katalogu `extensions/` - opis w `extensions/README.md` (Wtyczki → Dokumentacja rozszerzeń).

Integracje działają jak wtyczki: każda to samodzielny katalog w `integrations/`,
wykrywany automatycznie. Dodanie integracji **nie wymaga zmian w rdzeniu**.

Wtyczka jest **samowystarczalna** — cała logika API integracji mieszka w jej katalogu
(np. `integrations/blpaczka/BlpaczkaClient.php`). Rdzeń wywołuje integrację wyłącznie przez
kontrakt z rejestru (`PluginRegistry`), więc usunięcie katalogu wtyczki realnie usuwa integrację.
Wtyczka może (i powinna) korzystać ze wspólnych narzędzi rdzenia: `Pase\Support\Http`,
`Pase\Support\Logger`, `Pase\Domain\OrderStatus` — to nie psuje niezależności, tylko unika
duplikacji. Wzorce: `integrations/blpaczka/` (kurier), `integrations/allegrowysylka/` (kurier asynchroniczny, korzysta z OAuth Allegro), `integrations/woocommerce/` (zamówienia + magazyn),
`integrations/allegro/` (OAuth — tokeny w tabeli `integrations`, config z `.env`; klient budowany
przez `AllegroPlugin::makeClient($pdo, $config['allegro'])`).
`integrations/gs1/` (GS1 Polska: wtyczka bez zdolności z kontraktu — daje klienta API przez `client()`,
a stronę Magazyn → GS1 i tabelę `gs1_products` ma rdzeń w `Pase\Services\Gs1`; bez wtyczki strona znika z menu).
`integrations/payu/` (PayU: saldo sklepu i wypłaty przez Payouts API; ten sam wzorzec co GS1 — stronę System → PayU
i tabelę `payu_payouts` ma rdzeń w `Pase\Services\PayuPayouts`).
`integrations/orlenpaczka/` (ORLEN Paczka: kurier na API SOAP składanym bez php-soap, punkt odbioru z wyszukiwarką
i własne śledzenie paczek - wzorzec dla przewoźnika z punktami).
`integrations/tiktokshop/` (TikTok Shop: klient Open API 202309 z podpisem HMAC; OAuth przez `tiktokshop_connect.php`,
import zamówień, stany CRM do SKU TikTok i numery przesyłek robi rdzeń w `Pase\Services\TiktokShop`, uruchamiany z workera;
jak Allegro deklaruje `ORDER_SOURCE` bez interfejsu `OrderSource`, bo zamówienia zdejmują stan w CRM).

Gdy wtyczka ma logikę specyficzną dla kanału, której nie pokrywa znormalizowany kontrakt
(np. import stronami z filtrem, tryby diagnostyczne, tworzenie zamówień), może wystawić publiczną
metodę `client()` zwracającą własny klient API (jak `WoocommerceClient`). Rdzeń sięga po niego przez
`Pase\Plugin\IntegrationClient::for($type, $config)` — bez twardego importu klasy wtyczki.

## Instalacja wtyczki

Dwie drogi:

1. **FTP** — wgraj katalog wtyczki do `integrations/`. System wykryje ją od razu.
2. **Panel → Konfiguracja → Wtyczki** (`plugins.php`, tylko **administrator**) — wgraj plik
   **ZIP**. Paczka musi zawierać katalog wtyczki z `register.php`. Instalator:
   - chroni przed zip-slip (odrzuca ścieżki `..`),
   - waliduje strukturę i manifest (wadliwą paczkę wycofuje),
   - skanuje kod pod kątem niebezpiecznych funkcji (`eval`, `exec`, `system`… → ostrzeżenie),
   - robi **kopię zapasową** poprzedniej wersji w `storage/plugin_backups/`.
   Na stronie Wtyczki możesz też **włączać/wyłączać** (znacznik `.disabled`) i **usuwać** wtyczki.

> **Bezpieczeństwo:** instalacja wtyczki wykonuje obcy kod PHP z pełnym dostępem do bazy,
> kluczy API i plików. Na shared hostingu **nie ma sandboxa** — instaluj wyłącznie wtyczki
> z zaufanego źródła. Skan funkcji utrudnia nadużycia, ale nie gwarantuje bezpieczeństwa.
> Obcą wtyczkę sprawdź najpierw w sandboxie: `sh docker/sandbox/run.sh wtyczka.zip` (CRM w Dockerze na
> fikcyjnych danych, bez internetu; raport pokazuje, co wtyczka robi z bazą, plikami i siecią). Opis: `docker/sandbox/README.md`.

## Struktura wtyczki

```
integrations/
  mojkanal/
    register.php        <- zwraca FQCN klasy wtyczki
    MojkanalPlugin.php  <- klasa wtyczki
```

`register.php`:
```php
<?php
return \PasePlugin\Mojkanal\MojkanalPlugin::class;
```

> **Nazewnictwo plików:** plik klasy NIE może nazywać się `plugin.php` (kolizja z
> `register.php` na systemach case-insensitive). Użyj nazwy klasy, np. `MojkanalPlugin.php`.

## Namespace i autoload

> **Nazwy w kodzie:** system nazywa się teraz **CRM**, ale przestrzenie nazw zostały po dawnej
> nazwie: rdzeń to `Pase\...`, a wtyczki `PasePlugin\...`. To celowe — zmiana złamałaby
> istniejące wtyczki, więc w kodzie używaj ich tak jak w przykładach poniżej.

Wtyczki używają namespace `PasePlugin\<Vendor>\...`, mapowanego na `integrations/<vendor>/...`
(pierwszy segment = nazwa katalogu, małymi literami):

```
PasePlugin\Mojkanal\MojkanalPlugin  ->  integrations/mojkanal/MojkanalPlugin.php
```

Brak Composera — autoloader rdzenia ładuje to automatycznie.

## Minimalna wtyczka

```php
<?php
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
            type: 'mojkanal',                       // unikalny, [a-z0-9_]
            name: 'Mój Kanał',
            version: '1.0.0',
            author: 'Twoja Firma',
            capabilities: [Capability::ORDER_SOURCE],
            fields: [                               // pola formularza w panelu
                ['key' => 'api_url', 'label' => 'URL API', 'type' => 'url', 'required' => true],
                ['key' => 'token',   'label' => 'Token',   'type' => 'password', 'secret' => true],
            ],
            icon: '🛒',
            // Logo kanału przy źródle na liście zamówień: znacznik <svg>, kwadrat (viewBox 0 0 24 24).
            // Bez logo lista pokaże kwadrat w kolorze `color` z pierwszą literą nazwy.
            logo: '<svg viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="#0a7"/></svg>',
            multiple: true,                         // można dodać wiele kont
        );
    }

    public function testConnection(): array
    {
        $url = $this->cfg('api_url');               // dostęp do configu konta
        // ... sprawdź połączenie ...
        return ['ok' => true, 'message' => 'Połączenie OK'];
    }

    // --- OrderSource ---
    public function fetchOrders(int $page, int $perPage = 50, ?string $after = null): array
    {
        // pobierz i zwróć tablicę znormalizowanych zamówień
        return [];
    }

    public function pushOrderStatus(string $externalOrderId, string $crmStatus): bool
    {
        return true;
    }
}
```

`AbstractPlugin` daje gotowe `withConfig()` oraz helper `cfg('klucz', domyślne)`.

## Pola manifestu (`PluginManifest`)

| Pole           | Domyślnie    | Opis                                                                 |
|----------------|--------------|----------------------------------------------------------------------|
| `type`         | —            | unikalny klucz typu, `[a-z0-9_]` (wymagane)                          |
| `name`         | —            | nazwa wyświetlana w panelu (wymagane)                                |
| `version`      | `1.0.0`      | wersja wtyczki (semver)                                              |
| `author`       | pusty        | autor, widoczny na stronie Wtyczki                                   |
| `capabilities` | `[]`         | zdolności `Capability::*` (patrz niżej)                              |
| `fields`       | `[]`         | pola formularza konta (patrz „Pola konfiguracji”)                    |
| `color`        | `#667eea`    | kolor akcentu w UI; tło zastępczego logo z literą                    |
| `icon`         | `🔌`         | emoji/ikona w panelu integracji                                      |
| `logo`         | pusty        | logo kanału: znacznik `<svg>`, kwadrat (`viewBox="0 0 24 24"`)       |
| `multiple`     | `true`       | czy można dodać wiele kont tego typu                                 |
| `description`  | pusty        | krótki opis w galerii dodawania integracji                           |
| `category`     | pusty        | kategoria w galerii (`Capability::CAT_*`); pusta = ze zdolności      |

**Logo (`logo`)** pokazuje się przy źródle zamówienia na liście zamówień. Wartość musi zaczynać się
od `<svg` (bez nagłówka `<?xml` — inaczej zostanie pominięta) i nie powinna zawierać skryptów; najlepiej kwadratowy
`viewBox="0 0 24 24"` — zostanie przeskalowany do rozmiaru ikony. Bez logo lista pokaże kwadrat
w kolorze `color` z pierwszą literą nazwy wtyczki.

## Zdolności (capabilities)

Wtyczka deklaruje zdolności w manifeście i implementuje odpowiednie interfejsy
(`Pase\Plugin\Contract\*`):

| Capability                 | Interfejs     | Do czego                                  |
|----------------------------|---------------|-------------------------------------------|
| `Capability::ORDER_SOURCE` | `OrderSource` | pobieranie zamówień, push statusu         |
| `Capability::WAREHOUSE`    | `Warehouse`   | produkty, stany, ceny (sync magazynu)     |
| `Capability::COURIER`      | `Courier`     | przesyłki, etykiety, anulowanie, wycena   |
| `Capability::INVOICING`    | `Invoicing`   | faktury / paragony                        |

Jedna wtyczka może łączyć kilka zdolności (np. Woo = OrderSource + Warehouse).

**Opcjonalnie:** wtyczka może implementować `Pase\Plugin\Contract\ProvidesTemplates`
(metoda `templates()` zwraca `[{name, url, desc, icon}]`), aby jej szablony pojawiły się
w hubie **Konfiguracja → Szablony** automatycznie (np. Allegro → szablony opisu ofert).

## Wtyczki kurierskie — szczegóły

Na stronie zamówienia sekcja **Przesyłki → Nadaj przez** pokazuje przycisk dla każdego aktywnego
konta wtyczki z `Capability::COURIER` (plus „Inne” — ręczny wpis numeru). BLPaczka ma własny
formularz (`shipment_create.php`); pozostałe wtyczki obsługuje wspólny `public/admin/courier_shipment.php`:

- `listServices(['order' => ['row' => …, 'payload' => …]])` — zwróć
  `['ok', 'message', 'default', 'services' => [['id', 'label', 'group'], …]]`; lista trafia do pola
  „Umowa / usługa”. `group` grupuje opcje (np. `allegro` = umowa Allegro, `own` = własna umowa).
- `createShipment($shipment)` dostaje: `order` (`row` z `woo_orders` + zdekodowany `payload`),
  `parcel` (`weight` kg, `x`/`y`/`z` cm), `service` (wybrane `id`), `label_format`, `text_on_label`.
  Zwraca jak w kontrakcie; opcjonalnie `courier_label` (nazwa w tabeli przesyłek).
- `getLabel($externalId, $format)` — `$format` to `A4 | LBL | ZPL | EPL`; typ pliku (PDF/ZPL)
  rozpoznajemy po `mime`/rozszerzeniu `filename`, więc wtyczka może oddać inny, niż poproszono.
- `external_id` jest **tekstem** (np. UUID) — rdzeń nie rzutuje go na liczbę.

Opcjonalnie:

- `supportsOrder(array $order): bool` — ogranicza, przy jakich zamówieniach wtyczka się pokazuje
  (np. „Wysyłam z Allegro” tylko przy zamówieniach z Allegro).
- `proposal(array $order): array` — podpowiedź paczki (`package` => `weight/x/y/z`, `sender`, `message`)
  do wypełnienia formularza.
- W odpowiedzi `listServices()` dodatkowo: `groups` (`['klucz grupy' => 'nazwa']` - nagłówki grup usług) oraz
  `point` (`label`, `value`, `note`, `placeholder`, `required`, `search`) - formularz pokaże pole punktu odbioru,
  a wybrany kod trafi do `createShipment()` jako `point`. Przy `search => true` wtyczka ma metodę
  `searchPoints(string $q): array` (`['ok', 'message', 'points' => [['code', 'label'], …]]`) - podpowiedzi przy wpisywaniu.
- Własne śledzenie: metody `trackingCarrier(): string` (id przewoźnika jak w Allegro, np. `ORLEN`) i
  `trackWaybills(array $numery): array` (`['ok', 'message', 'waybills' => [numer => [['code', 'description', 'occurredAt'], …]]]`,
  kody jak w śledzeniu Allegro: `PENDING`, `IN_TRANSIT`, `RELEASED_FOR_DELIVERY`, `AVAILABLE_FOR_PICKUP`, `NOTICE_LEFT`,
  `DELIVERED`, `RETURNED`, `ISSUE`). `Services\ShipmentTracking` pyta wtyczkę o paczki nadane przez jej konto i o paczki
  jej przewoźnika wpisane ręcznie, zanim zapyta Allegro. Gdy przewoźnik sam przysyła statusy, wtyczka ma
  `pushActive(): bool` (true = harmonogram pyta ją tylko co `ShipmentTracking::PUSH_RECHECK_HOURS`), a status z
  powiadomienia zapisuje `ShipmentTracking::applyPush($pdo, $przewoźnik, $numer, ['code', 'description', 'occurredAt'])`.
- Webhooki: `handleWebhook(array $request, PDO $pdo): array` (`['status' => kod HTTP, 'body' => tekst]`) odbiera żądania
  na `public/webhook_plugin.php?a=<id konta>` (`$request`: `method`, `headers` z kluczami małymi literami, `query`, `body`,
  `account_id`). Autoryzację robi wtyczka. Przykład: Uniwersalny Pusher ORLEN Paczka.
- Panel na stronie integracji: `adminInfo(array $ctx): array` (akapity tekstu), `adminActions(): array`
  (`['akcja' => 'etykieta przycisku']`) i `adminAction(string $akcja, array $ctx): array` (`['ok', 'message']`).
  `$ctx`: `account_id`, `base_url` (APP_BASE_URL), `pdo`, `local` (tryb lokalny), w akcjach też `save` (fn(array $config)
  zapisuje config konta).
- `Pase\Plugin\Contract\CourierAsync` — dla przewoźników tworzących przesyłkę asynchronicznie:
  `createShipment()` może zwrócić `pending => true` z tymczasowym `external_id`; rdzeń zapisze
  przesyłkę jako `pending` i przy kolejnym otwarciu zamówienia wywoła `resolveShipment($id)`.

## Wspólny kontekst (`Pase\Support\Runtime`)

Wtyczka dostaje od rejestru tylko config swojego konta. Gdy potrzebuje bazy albo konfiguracji
aplikacji (np. „Wysyłam z Allegro” korzysta z tokenów OAuth konta Allegro), używa
`Runtime::pdo()` i `Runtime::config('allegro')` zamiast ponownie ładować `config/config.php`.
Wzorzec: `integrations/allegrowysylka/`.

## Pola konfiguracji (`fields`)

Sterują formularzem konta w panelu. Klucze: `key`, `label`, `type`
(`text|password|url|select|checkbox|textarea`), `required`, `secret` (maskuj i nie
nadpisuj pustym), `options` (dla `select`), `help`, `default`.

## Dobre praktyki

- **Nie ufaj wejściu** — waliduj odpowiedzi API; rzucaj wyjątki z czytelnym komunikatem.
- **Sekrety** oznacz `'secret' => true` — panel ich nie pokaże i nie skasuje pustym polem.
- **Bez efektów ubocznych w `manifest()`** — ma tylko opisywać.
- **Idempotencja** — pobieranie zamówień/produktów powinno być bezpieczne przy powtórzeniu.

## Uwaga bezpieczeństwa

Wtyczka to pełnoprawny kod PHP z dostępem do bazy, kluczy i plików. Instaluj wyłącznie
wtyczki z zaufanego źródła. Wgrywanie ZIP przez panel wykonuje obcy kod — traktuj jak
instalację wtyczki WordPress od nieznanego autora.
