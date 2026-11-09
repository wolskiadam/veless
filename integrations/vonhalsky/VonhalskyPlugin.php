<?php
declare(strict_types=1);

namespace PasePlugin\Vonhalsky;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka InPost vonHalsky - zamówienia z marketplace InPost, przyjmowanie/odrzucanie, stany i ceny ofert.
 *
 * Wtyczka daje klienta API (VonhalskyClient) i pola konta. Połączenie OAuth (vonhalsky_connect.php), import zamówień
 * na wspólną listę, przyjmowanie zamówień i wysyłanie stanów/cen CRM do ofert ma rdzeń w Pase\Services\Vonhalsky,
 * uruchamiany przez worker co kilka minut. Jak ERLI i TikTok Shop: deklaruje ORDER_SOURCE (filtr źródła na liście
 * zamówień), ale nie implementuje OrderSource - zamówienia nie idą ścieżką importu WooCommerce, bo zdejmują stan w CRM.
 */
final class VonhalskyPlugin extends AbstractPlugin
{
    public const TYPE = 'vonhalsky';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: self::TYPE,
            name: 'InPost vonHalsky',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::ORDER_SOURCE, Capability::WAREHOUSE],
            fields: [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true,
                 'help' => 'Dane integratora od InPost (apiportal@inpost.pl). API vonHalsky jest dostępne tylko dla zgłoszonych integratorów.'],
                // Nazwa pola kończy się na _secret - SecretStore szyfruje ją w configu konta.
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true, 'secret' => true],
                ['key' => 'environment', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcja (api.inpost-group.com)', 'stage' => 'Testowe (stage-api.inpost-group.com)']],
                ['key' => 'organization_id', 'label' => 'Organizacja (sklep)', 'type' => 'text',
                 'help' => 'Puste = CRM wybierze sam po połączeniu (pierwszy sklep konta). Wpisz ID, jeśli konto ma kilka sklepów.'],
                ['key' => 'import_days', 'label' => 'Pierwszy import zamówień z ostatnich (dni)', 'type' => 'text', 'default' => '7',
                 'help' => 'Tylko przy pierwszej synchronizacji; potem CRM pobiera zmiany od ostatniego sprawdzenia.'],
                ['key' => 'auto_accept', 'label' => 'Nowe zamówienia', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Przyjmuj automatycznie w InPost', 'off' => 'Przyjmuję ręcznie na stronie zamówienia']],
                ['key' => 'push_stock', 'label' => 'Stany magazynowe', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Wysyłaj stan CRM do ofert vonHalsky (dopasowanie po SKU)', 'off' => 'Nie wysyłaj stanów']],
                ['key' => 'push_price', 'label' => 'Ceny', 'type' => 'select', 'default' => 'off',
                 'options' => ['off' => 'Nie zmieniaj cen w vonHalsky', 'on' => 'Wysyłaj cenę z magazynu CRM (razy mnożnik) do ofert vonHalsky']],
                ['key' => 'price_multiplier', 'label' => 'Mnożnik ceny', 'type' => 'text', 'default' => '1.00',
                 'help' => 'Cena w vonHalsky = cena w magazynie × mnożnik, np. 1.05 to 5% drożej.'],
            ],
            color: '#ffcd00',
            icon: '📦',
            // Logo kanału na liście zamówień (Services\OrderChannelIcons).
            logo: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#ffcd00"/><text x="12" y="16" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="10" font-weight="700" fill="#1d1d1b">vH</text></svg>',
            multiple: true,   // każdy sklep (organizacja) to osobne konto
            description: 'Zamówienia z marketplace InPost vonHalsky na liście zamówień (z paczkomatem odbiorcy), przyjmowanie lub odrzucanie zamówień, stany i ceny CRM wysyłane do ofert po SKU.',
            category: Capability::CAT_MARKETPLACE
        );
    }

    public function testConnection(): array
    {
        // Token sprzedawcy jest w bazie - prawdziwy test robi strona integracji przez Pase\Services\Vonhalsky::test().
        return $this->client()->configured()
            ? ['ok' => true, 'message' => 'Dane integratora wpisane. Kliknij „Połącz z InPost vonHalsky”, żeby sprawdzić połączenie ze sklepem.']
            : ['ok' => false, 'message' => 'Wpisz Client ID i Client Secret od InPost.'];
    }

    /** Klient bez tokenu (strona zgody, wymiana kodu) albo z tokenem sprzedawcy (reszta API). */
    public function client(?string $accessToken = null, ?callable $transport = null): VonhalskyClient
    {
        return new VonhalskyClient($this->config, $accessToken, $transport);
    }
}
