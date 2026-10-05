<?php
declare(strict_types=1);

namespace PasePlugin\Blpaczka;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\Courier;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka BLPaczka (kurier) — SAMOWYSTARCZALNA. Logika API mieszka w tej wtyczce
 * (BlpaczkaClient), rdzeń woła ją wyłącznie przez kontrakt Courier z rejestru.
 */
final class BlpaczkaPlugin extends AbstractPlugin implements Courier
{
    /** Kody form płatności BLPaczka (CartOrder.payment) -> etykieta. „bank” = skarbonka (prepaid). */
    public const PAYMENTS = [
        'bank' => 'Skarbonka (prepaid)', 'pay_later' => 'Płatność odroczona',
        'paynow' => 'Płatności online (Paynow)', 'online' => 'Płatności online (Transferuj)',
        'dotpay' => 'Płatności online (Dotpay)', 'monetivo' => 'Płatności online (Monetivo)',
    ];

    /** Forma płatności z konfiguracji; puste pole = skarbonka. */
    public static function paymentCode(array $config): string
    {
        $p = trim((string) ($config['payment'] ?? ''));
        return $p !== '' ? $p : 'bank';
    }

    /** Czy odpowiedź BLPaczki to brak środków / brak formy płatności. */
    public static function isFundsError(string $message): bool
    {
        return (bool) preg_match('/skarbon|formy p[łl]atno/iu', $message);
    }

    /**
     * Podpowiedź po odrzuceniu nadania z braku środków (null = inny błąd).
     * @param array{login:string, env:string, payment:string, payment_label:string, balance:?float} $info
     */
    public static function fundsHint(string $message, array $info): ?string
    {
        if (!self::isFundsError($message)) {
            return null;
        }
        $parts = [];
        if ($info['env'] === 'sandbox') {
            $parts[] = 'Integracja BLPaczka jest ustawiona na środowisko Sandbox (testowe) - to osobne konto z osobną, pustą skarbonką. '
                     . 'Zmień „Środowisko” na Produkcja w Konfiguracja → Integracje → BLPaczka.';
        }
        $who = $info['login'] !== '' ? ' konta ' . $info['login'] : '';
        if ($info['balance'] !== null) {
            $parts[] = 'BLPaczka widzi na skarbonce' . $who . ': ' . number_format((float) $info['balance'], 2, ',', ' ') . ' zł.';
        }
        if ($info['payment'] === 'bank') {
            $parts[] = 'CRM płaci za nadanie ze skarbonki (prepaid). Pieniądze muszą być doładowane właśnie na skarbonkę w panelu BLPaczka; '
                     . 'jeśli masz płatność odroczoną (fakturę), wybierz ją w „Forma płatności za nadanie” w ustawieniach integracji.';
        } else {
            $parts[] = 'Forma płatności w CRM: ' . $info['payment_label'] . ' - BLPaczka jej nie przyjął. Sprawdź dostępne formy przyciskiem '
                     . '„Pokaż dostępne formy płatności” w ustawieniach integracji.';
        }
        return implode(' ', $parts);
    }

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'blpaczka',
            name: 'BLPaczka',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::COURIER],
            fields: [
                ['key' => 'login',   'label' => 'Login (e-mail konta BLPaczka)', 'type' => 'text', 'required' => true],
                ['key' => 'api_key', 'label' => 'Klucz API', 'type' => 'password', 'required' => true, 'secret' => true],
                ['key' => 'env', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcja', 'sandbox' => 'Sandbox']],
                ['key' => 'payment', 'label' => 'Forma płatności za nadanie', 'type' => 'select', 'default' => 'bank',
                 'options' => self::PAYMENTS],
                ['key' => 'label_format', 'label' => 'Domyślny format etykiety', 'type' => 'select', 'default' => 'A4',
                 'options' => ['A4' => 'A4', 'LBL' => 'Termiczna (A6)', 'ZPL' => 'ZPL (Zebra)', 'EPL' => 'EPL (Zebra)']],
                ['key' => 'pickup_mode', 'label' => 'Domyślny tryb nadania', 'type' => 'select', 'default' => 'courier',
                 'options' => ['courier' => 'Odbiór przez kuriera', 'self' => 'Sam dostarczę do przewoźnika'],
                 'help' => 'Tryb wstępnie zaznaczony przy nadawaniu paczki (można zmienić per przesyłkę).'],
                ['key' => 'favorite_couriers', 'label' => 'Ulubieni kurierzy (kody, po przecinku)', 'type' => 'text',
                 'help' => 'Pokażą się jako szybkie przyciski przy nadawaniu.'],
            ],
            color: '#e11d48',
            icon: '📦',
            multiple: false,
            description: 'Nadawanie przesyłek, etykiety (A4/termiczna/ZPL/EPL), anulowanie.'
        );
    }

    private function client(): BlpaczkaClient
    {
        return new BlpaczkaClient($this->config);
    }

    public function testConnection(): array
    {
        $r = $this->client()->testConnection();
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? '')];
    }

    public function listServices(array $parcel): array
    {
        return $this->client()->getCouriers($parcel);
    }

    public function listPaymentOptions(): array
    {
        return $this->client()->getPaymentOptions();
    }

    /** Saldo skarbonki BLPaczka (poza kontraktem Courier). @return array{ok:bool, balance:?float, message:string} */
    public function balance(): array
    {
        $r = $this->client()->getBalance();
        return ['ok' => $r['ok'], 'balance' => $r['balance'], 'message' => $r['message'], 'raw' => $r['raw']];
    }

    public function quote(array $params): array
    {
        $r = $this->client()->getValuation($params);
        return [
            'ok'      => (bool) ($r['ok'] ?? false),
            'price'   => $r['price'] ?? null,
            'message' => (string) ($r['message'] ?? ''),
            'raw'     => $r['raw'] ?? $r,
        ];
    }

    public function createShipment(array $shipment): array
    {
        $r = $this->client()->createOrder($shipment);
        return [
            'ok'          => (bool) ($r['ok'] ?? false),
            'message'     => (string) ($r['message'] ?? ''),
            'waybill_no'  => $r['waybill_no'] ?? null,
            'external_id' => $r['order_id'] ?? null,
            'price'       => $r['price'] ?? null,
            'label_link'  => $r['label_link'] ?? null,
            'raw'         => $r['raw'] ?? $r,
        ];
    }

    public function getLabel(string $externalId, string $format = 'A4'): array
    {
        $r = $this->client()->getWaybill((int) $externalId, $format);
        return [
            'ok'       => (bool) ($r['ok'] ?? false),
            'content'  => $r['content'] ?? null,
            'filename' => $r['filename'] ?? null,
            'mime'     => $r['mime'] ?? null,
            'message'  => (string) ($r['message'] ?? ''),
        ];
    }

    public function cancelShipment(string $externalId): array
    {
        $r = $this->client()->cancelOrder((int) $externalId);
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? '')];
    }
}
