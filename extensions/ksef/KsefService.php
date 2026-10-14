<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;
use PDO;

/**
 * Wystawianie e-faktur z zamówień i pilnowanie ich statusu w KSeF.
 *
 * issue():   dane zamówienia -> XML FA(3) z kolejnym numerem -> zapis (status sending) -> logowanie tokenem
 *            -> sesja -> wysyłka -> zamknięcie sesji -> krótkie czekanie na wynik weryfikacji.
 * refresh(): dopytuje KSeF o fakturę w toku (numer KSeF albo powód odrzucenia) i pobiera UPO.
 */
final class KsefService
{
    /** Ile razy pytamy o wynik zaraz po wysyłce (KSeF zwykle odpowiada w kilka sekund). */
    private const WAIT_TRIES = 6;

    private ?string $accessToken = null;
    private ?KsefApi $api = null;
    /** @var callable(string):KsefApi */
    private $apiFactory;
    /** @var callable(int):void */
    private $sleep;

    /**
     * @param array<string,mixed> $settings ustawienia rozszerzenia (ExtensionSettings)
     * @param string|\Closure():string $token token KSeF (albo funkcja, która go odczyta dopiero, gdy będzie potrzebny)
     * @param callable(string $env):KsefApi|null $apiFactory (testy)
     * @param callable(int $ms):void|null $sleep (testy)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $settings,
        private string|\Closure $token,
        ?callable $apiFactory = null,
        ?callable $sleep = null
    ) {
        $this->apiFactory = $apiFactory ?? static fn(string $env): KsefApi => new KsefApi($env);
        $this->sleep = $sleep ?? static function (int $ms): void { usleep($ms * 1000); };
    }

    public function env(): string
    {
        $env = (string) ($this->settings['environment'] ?? 'test');
        return isset(KsefApi::ENVIRONMENTS[$env]) ? $env : 'test';
    }

    /** NIP firmy z ustawień (same cyfry). */
    public function nip(): string
    {
        return \Pase\Services\InvoiceData::normalizeNip((string) ($this->settings['nip'] ?? ''));
    }

    /** @return list<string> czego brakuje do wysyłki */
    public function missing(): array
    {
        $out = [];
        if (!Fa3Xml::validNip($this->nip())) {
            $out[] = 'NIP firmy';
        }
        if (trim((string) ($this->settings['seller_name'] ?? '')) === '') {
            $out[] = 'nazwa firmy';
        }
        if (trim((string) ($this->settings['seller_address1'] ?? '')) === '') {
            $out[] = 'adres firmy';
        }
        if (trim($this->token()) === '') {
            $out[] = 'token KSeF';
        }
        return $out;
    }

    public function invoices(): Invoices
    {
        return new Invoices($this->pdo);
    }

    /**
     * Podgląd faktury zamówienia (bez numeru i bez wysyłki).
     * @return array{invoice:array<string,mixed>,totals:array<string,mixed>,error:?string}
     */
    public function preview(int $orderId): array
    {
        $inv = (new OrderInvoice($this->pdo, $this->settings))->build($this->order($orderId));
        $inv['number'] = '(numer nadany przy wysyłce)';
        $error = null;
        try {
            Fa3Xml::validate($inv);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        return ['invoice' => $inv, 'totals' => Fa3Xml::totals($inv['lines']), 'error' => $error];
    }

    /**
     * Wystawia e-fakturę zamówienia i wysyła ją do KSeF.
     * @return array<string,mixed> wiersz e-faktury po wysyłce
     */
    public function issue(int $orderId, string $user): array
    {
        if ($this->missing() !== []) {
            throw new \RuntimeException('Uzupełnij ustawienia KSeF: ' . implode(', ', $this->missing()) . '.');
        }
        $order = $this->order($orderId);
        $env = $this->env();
        $built = (new OrderInvoice($this->pdo, $this->settings))->build($order);
        $invoices = $this->invoices();
        $row = $invoices->reserve($orderId, $env, (string) ($this->settings['number_format'] ?? ''), $user,
            static function (string $number) use ($built): array {
                $inv = $built + ['number' => $number];
                $xml = Fa3Xml::build($inv);
                return ['xml' => $xml, 'issue_date' => $inv['issue_date'], 'gross' => Fa3Xml::totals($inv['lines'])['gross'],
                    'buyer' => (string) ($inv['buyer']['name'] ?? '')];
            });

        try {
            $api = $this->api();
            $session = $api->openSession($this->access());
            try {
                $invoiceRef = $api->sendInvoice($this->access(), $session, (string) $row['xml']);
            } catch (\Throwable $e) {
                $this->closeQuietly($session['ref']);
                throw $e;
            }
            $invoices->update($orderId, $env, ['status' => 'processing', 'session_ref' => $session['ref'], 'invoice_ref' => $invoiceRef,
                'message' => 'Wysłano, KSeF sprawdza fakturę.']);
            $this->closeQuietly($session['ref']);
        } catch (\Throwable $e) {
            $invoices->update($orderId, $env, ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
            Logger::warn('KSeF: wysyłka e-faktury zamówienia ' . $orderId . ' nie powiodła się: ' . $e->getMessage());
            throw new \RuntimeException('Nie wysłano e-faktury ' . $row['number'] . ': ' . $e->getMessage(), 0, $e);
        }

        for ($i = 0; $i < self::WAIT_TRIES; $i++) {
            $row = $this->refresh($orderId);
            if (!in_array($row['status'], Invoices::OPEN, true)) {
                break;
            }
            ($this->sleep)(($i + 1) * 500);
        }
        return $row;
    }

    /**
     * Sprawdza w KSeF fakturę w toku (albo dociąga brakujące UPO przyjętej).
     * @return array<string,mixed> aktualny wiersz
     */
    public function refresh(int $orderId): array
    {
        $env = $this->env();
        $invoices = $this->invoices();
        $row = $invoices->get($orderId, $env);
        if ($row === null) {
            throw new \RuntimeException('To zamówienie nie ma e-faktury w KSeF (' . KsefApi::ENVIRONMENTS[$env]['label'] . ').');
        }
        if (empty($row['session_ref']) || empty($row['invoice_ref']) || ($row['status'] === 'accepted' && $row['upo'] !== null)
            || in_array($row['status'], ['rejected', 'error'], true)) {
            return $row;
        }
        $api = $this->api();
        $st = $api->invoiceStatus($this->access(), (string) $row['session_ref'], (string) $row['invoice_ref']);
        $code = (int) ($st['status']['code'] ?? 0);
        if ($code === 200 && !empty($st['ksefNumber'])) {
            $fields = ['status' => 'accepted', 'ksef_number' => (string) $st['ksefNumber'],
                'message' => 'Przyjęta w KSeF' . (!empty($st['acquisitionDate']) ? ' ' . $st['acquisitionDate'] : '') . '.'];
            try {
                $fields['upo'] = $api->invoiceUpo($this->access(), (string) $row['session_ref'], (string) $row['invoice_ref']);
            } catch (\Throwable $e) {
                $fields['message'] .= ' UPO jeszcze niedostępne - odśwież później.';
            }
            $invoices->update($orderId, $env, $fields);
        } elseif ($code >= 300) {
            $invoices->update($orderId, $env, ['status' => 'rejected', 'message' => 'KSeF odrzucił fakturę: ' . KsefApi::statusText($st['status'] ?? [], 'kod ' . $code)]);
        } else {
            $invoices->update($orderId, $env, ['status' => 'processing', 'message' => KsefApi::statusText($st['status'] ?? [], 'KSeF sprawdza fakturę.')]);
        }
        return (array) $invoices->get($orderId, $env);
    }

    /** Odświeża wszystkie faktury w toku i przyjęte bez UPO. @return int ile sprawdzono */
    public function refreshOpen(int $limit = 50): int
    {
        $n = 0;
        $rows = [...$this->invoices()->latest($this->env(), $limit, 'processing'), ...array_filter($this->invoices()->latest($this->env(), $limit, 'accepted'),
            static fn(array $r): bool => empty($r['has_upo']))];
        foreach ($rows as $r) {
            try {
                $this->refresh((int) $r['woo_order_id']);
                $n++;
            } catch (\Throwable $e) {
                Logger::warn('KSeF: odświeżenie e-faktury zamówienia ' . $r['woo_order_id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** Sprawdza logowanie tokenem w wybranym środowisku. */
    public function testConnection(): string
    {
        $missing = array_values(array_filter($this->missing(), static fn(string $m): bool => in_array($m, ['NIP firmy', 'token KSeF'], true)));
        if ($missing !== []) {
            throw new \RuntimeException('Brakuje: ' . implode(', ', $missing) . '.');
        }
        $this->access();
        return 'Połączenie z KSeF działa (' . KsefApi::ENVIRONMENTS[$this->env()]['label'] . ', NIP ' . $this->nip() . ').';
    }

    /** Link weryfikacyjny faktury (KOD I): adres QR / NIP sprzedawcy / data wystawienia / skrót SHA-256 XML. */
    public static function verificationUrl(string $env, string $nip, string $issueDate, string $xml): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate);
        return KsefApi::qrBase($env) . '/invoice/' . rawurlencode($nip) . '/' . ($d ? $d->format('d-m-Y') : $issueDate) . '/' . KsefCrypto::sha256Url($xml);
    }

    /** @return array<string,mixed> */
    private function order(int $orderId): array
    {
        $order = (new WooOrderRepository($this->pdo))->find($orderId);
        if ($order === null) {
            throw new \RuntimeException('Nie znaleziono zamówienia ' . $orderId . '.');
        }
        return $order;
    }

    private function api(): KsefApi
    {
        return $this->api ??= ($this->apiFactory)($this->env());
    }

    private function access(): string
    {
        return $this->accessToken ??= $this->api()->login($this->nip(), $this->token());
    }

    private function token(): string
    {
        if ($this->token instanceof \Closure) {
            $this->token = (string) ($this->token)();
        }
        return $this->token;
    }

    private function closeQuietly(string $sessionRef): void
    {
        try {
            $this->api()->closeSession($this->access(), $sessionRef);
        } catch (\Throwable $e) {
            Logger::warn('KSeF: zamknięcie sesji ' . $sessionRef . ': ' . $e->getMessage());
        }
    }
}
