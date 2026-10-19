<?php
declare(strict_types=1);

namespace PasePlugin\Erli;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka ERLI - zamówienia, wystawianie ofert, stany, ceny i numery przesyłek (ERLI Shop API).
 *
 * Wtyczka daje klienta API (ErliClient) i pola konta. Import zamówień na wspólną listę, wysyłanie stanów CRM
 * do produktów ERLI i numerów przesyłek ma rdzeń w Pase\Services\Erli, uruchamiany przez worker co kilka minut.
 * Jak Allegro i TikTok Shop: deklaruje ORDER_SOURCE (filtr źródła na liście zamówień), ale nie implementuje
 * OrderSource - zamówienia nie idą ścieżką importu WooCommerce, bo zdejmują stan w CRM.
 */
final class ErliPlugin extends AbstractPlugin
{
    public const TYPE = 'erli';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: self::TYPE,
            name: 'ERLI',
            version: '1.1.0',
            author: 'Veless',
            capabilities: [Capability::ORDER_SOURCE, Capability::WAREHOUSE],
            fields: [
                // Nazwa pola kończy się na _key - SecretStore szyfruje ją w configu konta.
                ['key' => 'api_key', 'label' => 'Klucz API', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Panel sprzedawcy ERLI → Ustawienia → Integracje (API): wygeneruj klucz i wklej go tutaj.'],
                ['key' => 'environment', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcja (erli.pl)', 'sandbox' => 'Testowe (sandbox.erli.dev)']],
                ['key' => 'import_days', 'label' => 'Pierwszy import zamówień z ostatnich (dni)', 'type' => 'text', 'default' => '7',
                 'help' => 'Tylko przy pierwszej synchronizacji; potem CRM pobiera zmiany od ostatniego sprawdzenia.'],
                ['key' => 'push_stock', 'label' => 'Stany magazynowe', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Wysyłaj stan CRM do produktów ERLI (dopasowanie po SKU)', 'off' => 'Nie wysyłaj stanów']],
                ['key' => 'push_tracking', 'label' => 'Numery przesyłek', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Wysyłaj numer przesyłki do ERLI (oznacza zamówienie jako wysłane)', 'off' => 'Nie wysyłaj numerów']],
                ['key' => 'push_price', 'label' => 'Ceny', 'type' => 'select', 'default' => 'off',
                 'options' => ['off' => 'Nie zmieniaj cen w ERLI', 'on' => 'Wysyłaj cenę z magazynu CRM (razy mnożnik) do produktów ERLI']],
                ['key' => 'price_multiplier', 'label' => 'Mnożnik ceny', 'type' => 'text', 'default' => '1.00',
                 'help' => 'Cena w ERLI = cena w magazynie × mnożnik, np. 1.05 to 5% drożej. Działa przy wysyłaniu cen i przy wystawianiu.'],
                ['key' => 'dispatch_days', 'label' => 'Czas wysyłki nowych ofert (dni robocze)', 'type' => 'text', 'default' => '1'],
                ['key' => 'tax_rate', 'label' => 'Stawka VAT nowych ofert', 'type' => 'select', 'default' => 'TAX_23',
                 'options' => ['TAX_23' => '23%', 'TAX_8' => '8%', 'TAX_5' => '5%', 'TAX_0' => '0%', 'TAX_ZW' => 'zw.', 'TAX_NP' => 'np.']],
            ],
            color: '#4295b8',
            icon: '🛍️',
            // Logo kanału na liście zamówień (Services\OrderChannelIcons).
            logo: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#4295b8"/><text x="12" y="16" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="9" font-weight="700" fill="#fff">erli</text></svg>',
            multiple: true,   // każdy sklep ERLI to osobne konto z własnym kluczem
            description: 'Zamówienia z ERLI na liście zamówień, wystawianie ofert z magazynu (szablony opisu, kategorie, parametry, cenniki wysyłek), stany i ceny CRM wysyłane do ERLI po SKU oraz numery przesyłek odsyłane do ERLI.',
            category: Capability::CAT_MARKETPLACE
        );
    }

    public function testConnection(): array
    {
        try {
            $shop = $this->client()->shop();
            $name = trim((string) ($shop['name'] ?? ''));
            $active = !array_key_exists('active', $shop) || !empty($shop['active']);
            return ['ok' => true, 'message' => 'Połączenie OK. Sklep ERLI: ' . ($name !== '' ? '„' . $name . '”' : '#' . ($shop['id'] ?? '?'))
                . ($active ? '.' : ' (sklep nieaktywny w ERLI).')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function client(?callable $transport = null): ErliClient
    {
        return new ErliClient($this->config, $transport);
    }
}
