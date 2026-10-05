<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\IntegrationClient;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use PDO;

/**
 * PayU: saldo sklepu i wypłaty (Payouts API) - strona System → PayU.
 *
 *  - Kilka sklepów: każdy sklep PayU to osobne konto wtyczki (własne klucze punktu płatności i shopId) - PayU zleca wypłatę
 *    tylko kluczami punktu należącego do sklepu, z którego wypłacamy. Metody przyjmują id konta (integration_accounts.id).
 *  - Saldo: odczyt przy wejściu na stronę (GET shops/{shopId}); ostatni odczyt zostaje w ustawieniach, żeby był widoczny,
 *    gdy PayU chwilowo nie odpowiada.
 *  - Wypłata: tylko po potwierdzeniu na stronie, zawsze konkretna kwota, na konto bankowe zapisane w PayU (bez wpisywania
 *    innego numeru konta). Tuż przed wysłaniem CRM jeszcze raz czyta saldo i nie zleci więcej niż „dostępne”.
 *    Każde zlecenie ma własny identyfikator (extPayoutId) zapisany w payu_payouts przed wysłaniem, więc odświeżenie strony
 *    ani podwójne kliknięcie nie zleci drugiej wypłaty.
 *
 * Połączenie z API (klient, klucze OAuth, shopId) daje wtyczka integrations/payu - konto w Konfiguracja → Integracje.
 * Rdzeń nie importuje klas wtyczki: klienta bierze przez IntegrationClient::for('payu', config).
 */
final class PayuPayouts
{
    public const TYPE = 'payu';
    public const S_LAST_BALANCE = 'PAYU_LAST_BALANCE';
    /** Statusy, po których wypłata już się nie zmieni (nie odpytujemy PayU). */
    public const FINAL = ['REALIZED', 'CANCELED', 'ERROR'];
    public const STATUS_LABELS = [
        'SENDING' => 'wysyłanie do PayU',
        'INIT' => 'przyjęta',
        'PENDING' => 'w realizacji',
        'WAITING' => 'oczekuje',
        'REALIZED' => 'zrealizowana',
        'CANCELED' => 'anulowana',
        'ERROR' => 'odrzucona',
        'UNKNOWN' => 'nie wiadomo - sprawdź w panelu PayU',
    ];

    /** @var callable(array):?object config konta => klient wtyczki (PayuClient) */
    private $clientFactory;
    /** @var array<int,?object> klient na konto - token OAuth pobierany raz */
    private array $clients = [];
    /** @var list<array<string,mixed>>|null */
    private ?array $accounts = null;

    /** @param ?callable(array):?object $clientFactory w testach: atrapa klienta; domyślnie klient z wtyczki */
    public function __construct(private readonly PDO $pdo, ?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn(array $config): ?object => IntegrationClient::for(self::TYPE, $config);
    }

    /** Czy wtyczka PayU jest zainstalowana i włączona (inaczej strona PayU jest ukryta). */
    public static function available(): bool
    {
        return PluginRegistry::get(self::TYPE) !== null;
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->exec('CREATE TABLE IF NOT EXISTS payu_payouts (
            id ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') . ',
            ext_payout_id VARCHAR(64) NOT NULL UNIQUE,
            payout_id VARCHAR(64) NULL,
            shop_id VARCHAR(32) NULL,
            amount INT NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT \'PLN\',
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL,
            error VARCHAR(500) NULL,
            sandbox TINYINT NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
        if (!LowStock::columnExists($pdo, 'payu_payouts', 'integration_id')) {
            $pdo->exec('ALTER TABLE payu_payouts ADD COLUMN integration_id INT NULL');   // konto (sklep), z którego wypłacono
        }
    }

    /** @return list<array<string,mixed>> aktywne konta wtyczki PayU (sklepy), w kolejności dodania */
    public function accounts(): array
    {
        return $this->accounts ??= (new IntegrationAccountRepository($this->pdo))->activeByType(self::TYPE);
    }

    /** Konto PayU po id (tylko aktywne) albo null. */
    public function account(int $accountId): ?array
    {
        foreach ($this->accounts() as $a) {
            if ((int) $a['id'] === $accountId) {
                return $a;
            }
        }
        return null;
    }

    /** Klient API z wtyczki (PayuClient) dla konta albo null, gdy wtyczki albo konta nie ma. */
    private function client(int $accountId): ?object
    {
        if (!array_key_exists($accountId, $this->clients)) {
            $acc = $this->account($accountId);
            $this->clients[$accountId] = $acc !== null ? ($this->clientFactory)((array) ($acc['config'] ?? [])) : null;
        }
        return $this->clients[$accountId];
    }

    public function configured(int $accountId): bool
    {
        $client = $this->client($accountId);
        return $client !== null && $client->configured();
    }

    public function sandbox(int $accountId): bool
    {
        $client = $this->client($accountId);
        return $client !== null && $client->sandbox();
    }

    /** Klient API konta (PayuClient) - rzuca czytelny wyjątek, gdy konta, wtyczki albo kluczy brak. */
    public function api(int $accountId): object
    {
        if ($this->account($accountId) === null) {
            throw new \RuntimeException('Nie ma takiego sklepu PayU (konto usunięte albo wyłączone w Konfiguracja → Integracje).');
        }
        $client = $this->client($accountId);
        if ($client === null) {
            throw new \RuntimeException('Wtyczka PayU jest wyłączona albo usunięta (Konfiguracja → Wtyczki).');
        }
        if (!$client->configured()) {
            throw new \RuntimeException('Brak danych dostępu do PayU. Wpisz je w Konfiguracja → Integracje → PayU.');
        }
        return $client;
    }

    // ------------------------------------------------------------------ saldo

    /**
     * Aktualne saldo z PayU (kwoty w groszach), zapamiętane też jako ostatni odczyt.
     * @return array{name:string,currency:string,total:int,available:int,read_at:string}
     */
    public function balance(int $accountId): array
    {
        $api = $this->api($accountId);
        [$st, $data] = $api->shop();
        if ($st !== 200) {
            throw new \RuntimeException($api::errorMessage($st, $data));
        }
        $b = (array) ($data['balance'] ?? []);
        if (!isset($b['available']) || !is_numeric($b['available'])) {
            throw new \RuntimeException('PayU nie podało salda sklepu. Sprawdź Id sklepu (shopId) w ustawieniach.');
        }
        $out = [
            'name' => (string) ($data['name'] ?? ''),
            'currency' => (string) ($b['currencyCode'] ?? $data['currencyCode'] ?? 'PLN'),
            'total' => (int) ($b['total'] ?? $b['available']),
            'available' => (int) $b['available'],
            'read_at' => date('Y-m-d H:i:s'),
        ];
        (new SettingsRepository($this->pdo))->setMany([self::S_LAST_BALANCE . '_' . $accountId => json_encode($out, JSON_UNESCAPED_UNICODE)]);
        return $out;
    }

    /** Ostatnio odczytane saldo (gdy PayU nie odpowiada) albo null. */
    public function lastBalance(int $accountId): ?array
    {
        $raw = (new SettingsRepository($this->pdo))->get(self::S_LAST_BALANCE . '_' . $accountId);
        $data = $raw !== null ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------ wypłaty

    /** Kwota wpisana w złotych („1 234,56”, „1234.5”) => grosze, albo null, gdy to nie kwota. */
    public static function parseAmount(string $input): ?int
    {
        $s = str_replace([' ', "\u{00A0}", 'zł', 'PLN', 'pln'], '', trim($input));
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }
        return (int) round(((float) $s) * 100);
    }

    public static function money(int $grosze, string $currency = 'PLN'): string
    {
        return number_format($grosze / 100, 2, ',', ' ') . ' ' . $currency;
    }

    /** Nowy identyfikator zlecenia - wkładany do formularza potwierdzenia, zanim cokolwiek pójdzie do PayU. */
    public static function newExtId(): string
    {
        return 'crm-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Sprawdza kwotę przed ekranem potwierdzenia.
     * @return int kwota w groszach
     */
    public function validateAmount(string $input, int $available): int
    {
        $amount = self::parseAmount($input);
        if ($amount === null || $amount <= 0) {
            throw new \RuntimeException('Wpisz kwotę wypłaty, np. 1500 albo 1500,50.');
        }
        if ($amount > $available) {
            throw new \RuntimeException('Kwota ' . self::money($amount) . ' jest większa niż dostępne do wypłaty ' . self::money($available) . '.');
        }
        return $amount;
    }

    /**
     * Zleca wypłatę (po potwierdzeniu). Kwota w groszach, na konto bankowe zapisane w PayU.
     * @return array<string,mixed> wiersz payu_payouts po zleceniu
     */
    public function order(int $accountId, int $amount, string $description, string $extId, string $user): array
    {
        self::migrate($this->pdo);
        if (!preg_match('/^crm-[0-9a-z-]{8,56}$/', $extId)) {
            throw new \RuntimeException('Nieprawidłowe zlecenie. Otwórz stronę PayU i zacznij od nowa.');
        }
        if ($this->find($extId) !== null) {
            throw new \RuntimeException('Ta wypłata została już zlecona - nic nie wysłano drugi raz.');
        }
        $description = mb_substr(trim($description), 0, 100);
        $api = $this->api($accountId);
        $balance = $this->balance($accountId);   // świeże saldo tuż przed wysłaniem
        if ($amount <= 0 || $amount > $balance['available']) {
            throw new \RuntimeException('Kwota ' . self::money($amount) . ' jest większa niż dostępne teraz do wypłaty ' . self::money($balance['available']) . '. Nic nie wysłano.');
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->prepare('INSERT INTO payu_payouts (ext_payout_id, integration_id, shop_id, amount, currency, description, status, sandbox, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$extId, $accountId, $api->shopId(), $amount, $balance['currency'], $description, 'SENDING', $api->sandbox() ? 1 : 0, $user, $now, $now]);
        } catch (\PDOException) {
            throw new \RuntimeException('Ta wypłata została już zlecona - nic nie wysłano drugi raz.');
        }

        try {
            [$st, $data] = $api->createPayout($amount, $description, $extId);
        } catch (\Throwable $e) {
            $this->setStatus($extId, 'UNKNOWN', null, $e->getMessage());
            throw new \RuntimeException('Nie wiadomo, czy PayU przyjęło wypłatę (' . $e->getMessage() . '). Sprawdź w panelu PayU, zanim zlecisz ją ponownie.');
        }
        $payoutId = (string) ($data['payout']['payoutId'] ?? '');
        if ($st >= 200 && $st < 300 && $payoutId !== '') {
            $this->setStatus($extId, strtoupper((string) ($data['payout']['status'] ?? 'PENDING')), $payoutId, null);
            return (array) $this->find($extId);
        }
        $msg = $api::errorMessage($st, $data);
        // Brak odpowiedzi albo błąd serwera PayU - zlecenie mogło dojść.
        $this->setStatus($extId, ($st === 0 || $st >= 500) ? 'UNKNOWN' : 'ERROR', null, $msg);
        throw new \RuntimeException($st === 0 || $st >= 500
            ? 'Nie wiadomo, czy PayU przyjęło wypłatę (' . $msg . '). Sprawdź w panelu PayU, zanim zlecisz ją ponownie.'
            : 'PayU nie przyjęło wypłaty: ' . $msg);
    }

    /** Odświeża status wypłaty z PayU. @return array<string,mixed> wiersz po odświeżeniu */
    public function refresh(int $id): array
    {
        $row = $this->pdo->prepare('SELECT * FROM payu_payouts WHERE id = ?');
        $row->execute([$id]);
        $p = $row->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            throw new \RuntimeException('Nie ma takiej wypłaty.');
        }
        if ((string) $p['payout_id'] === '') {
            return $p;   // PayU nie nadało numeru - nie ma czego sprawdzać
        }
        $api = $this->api($this->accountOf($p));
        [$st, $data] = $api->getPayout((string) $p['payout_id']);
        if ($st !== 200) {
            throw new \RuntimeException($api::errorMessage($st, $data));
        }
        $status = strtoupper((string) ($data['payout']['status'] ?? ''));
        if ($status !== '') {
            $this->setStatus((string) $p['ext_payout_id'], $status, (string) $p['payout_id'], null);
        }
        return (array) $this->find((string) $p['ext_payout_id']);
    }

    /** Odświeża wypłaty jeszcze w toku (przy wejściu na stronę). Błędy pomija - status zostaje poprzedni. */
    public function refreshOpen(int $limit = 5): void
    {
        $st = $this->pdo->query("SELECT id FROM payu_payouts WHERE payout_id IS NOT NULL AND status NOT IN ('" . implode("','", self::FINAL) . "') ORDER BY id DESC LIMIT " . (int) $limit);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $this->refresh((int) $id);
            } catch (\Throwable) {
            }
        }
    }

    /** @return list<array<string,mixed>> ostatnie wypłaty, najnowsze pierwsze */
    public function history(int $limit = 50): array
    {
        self::migrate($this->pdo);
        return $this->pdo->query('SELECT * FROM payu_payouts ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Konto wypłaty; starsze wypłaty (sprzed obsługi kilku sklepów) - konto z tym samym shopId albo pierwsze. */
    private function accountOf(array $payout): int
    {
        if ((int) ($payout['integration_id'] ?? 0) > 0) {
            return (int) $payout['integration_id'];
        }
        foreach ($this->accounts() as $a) {
            if (trim((string) ($a['config']['shop_id'] ?? '')) === (string) $payout['shop_id']) {
                return (int) $a['id'];
            }
        }
        return (int) ($this->accounts()[0]['id'] ?? 0);
    }

    /** Nazwa sklepu wypłaty do historii (nazwa konta w CRM, a gdy konta już nie ma - shopId). */
    public function shopLabel(array $payout): string
    {
        $acc = (int) ($payout['integration_id'] ?? 0) > 0 ? $this->account((int) $payout['integration_id']) : null;
        return $acc !== null ? (string) $acc['name'] : (string) ($payout['shop_id'] ?? '');
    }

    public function find(string $extId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM payu_payouts WHERE ext_payout_id = ?');
        $st->execute([$extId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[strtoupper($status)] ?? $status;
    }

    private function setStatus(string $extId, string $status, ?string $payoutId, ?string $error): void
    {
        $this->pdo->prepare('UPDATE payu_payouts SET status = ?, payout_id = COALESCE(?, payout_id), error = ?, updated_at = ? WHERE ext_payout_id = ?')
            ->execute([$status, $payoutId, $error !== null ? mb_substr($error, 0, 500) : null, date('Y-m-d H:i:s'), $extId]);
    }
}
