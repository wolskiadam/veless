<?php
declare(strict_types=1);

namespace PasePlugin\Tiktokshop;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka TikTok Shop - zamówienia, stany i numery przesyłek (Open API 202309).
 *
 * Wtyczka daje klienta API (TiktokShopClient) i pola konta. Połączenie OAuth (tiktokshop_connect.php), import zamówień
 * na wspólną listę, wysyłanie stanów CRM do SKU w TikTok i numerów przesyłek ma rdzeń w Pase\Services\TiktokShop,
 * uruchamiany przez worker co kilka minut. Jak Allegro: deklaruje ORDER_SOURCE (filtr źródła na liście zamówień),
 * ale nie implementuje OrderSource - zamówienia nie idą ścieżką importu WooCommerce, bo zdejmują stan w CRM.
 */
final class TiktokShopPlugin extends AbstractPlugin
{
    public const TYPE = 'tiktokshop';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: self::TYPE,
            name: 'TikTok Shop',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::ORDER_SOURCE],
            fields: [
                ['key' => 'app_key', 'label' => 'App key', 'type' => 'text', 'required' => true,
                 'help' => 'TikTok Shop Partner Center → App & Service → Twoja aplikacja → Basic information: „App key”.'],
                ['key' => 'app_secret', 'label' => 'App secret', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Z tego samego miejsca: „App secret”.'],
                ['key' => 'service_id', 'label' => 'Service ID', 'type' => 'text', 'required' => true,
                 'help' => 'Z tego samego miejsca: „Service ID” - potrzebny do strony zgody sprzedawcy (przycisk „Połącz z TikTok Shop”).'],
                ['key' => 'region', 'label' => 'Region sklepu', 'type' => 'select', 'default' => 'global',
                 'options' => ['global' => 'Europa i reszta świata', 'us' => 'USA']],
                ['key' => 'import_days', 'label' => 'Pierwszy import zamówień z ostatnich (dni)', 'type' => 'text', 'default' => '7',
                 'help' => 'Tylko przy pierwszym połączeniu; potem CRM pobiera zmiany od ostatniego sprawdzenia.'],
                ['key' => 'push_stock', 'label' => 'Stany magazynowe', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Wysyłaj stan CRM do produktów TikTok (dopasowanie po SKU)', 'off' => 'Nie wysyłaj stanów']],
                ['key' => 'push_tracking', 'label' => 'Numery przesyłek', 'type' => 'select', 'default' => 'on',
                 'options' => ['on' => 'Wysyłaj numer przesyłki do TikTok (oznacza zamówienie jako wysłane)', 'off' => 'Nie wysyłaj numerów']],
            ],
            color: '#111111',
            icon: '🎵',
            // Logo kanału na liście zamówień (Services\OrderChannelIcons).
            logo: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#111"/><path d="M13.3 5h2.2c.3 1.8 1.4 2.9 3.2 3.1v2.3a5.3 5.3 0 0 1-3.2-1v5.3a4.2 4.2 0 1 1-4.2-4.2h.4v2.3h-.4a1.9 1.9 0 1 0 2 1.9z" fill="#fff"/></svg>',
            multiple: true,   // każdy sklep TikTok (np. inny kraj) to osobne konto
            description: 'Zamówienia z TikTok Shop na liście zamówień, stany CRM wysyłane do produktów TikTok po SKU i numery przesyłek odsyłane do TikTok.',
            category: Capability::CAT_MARKETPLACE
        );
    }

    public function testConnection(): array
    {
        // Token sprzedawcy jest w bazie - prawdziwy test robi strona integracji przez Pase\Services\TiktokShop::test().
        $c = $this->client();
        return $c->configured()
            ? ['ok' => true, 'message' => 'Dane aplikacji wpisane. Kliknij „Połącz z TikTok Shop”, żeby sprawdzić połączenie ze sklepem.']
            : ['ok' => false, 'message' => 'Wpisz App key i App secret aplikacji z TikTok Shop Partner Center.'];
    }

    /** Klient bez tokenu (strona zgody, wymiana kodu) albo z tokenem i sklepem (reszta API). */
    public function client(?string $accessToken = null, ?string $shopCipher = null, ?callable $transport = null): TiktokShopClient
    {
        return new TiktokShopClient($this->config, $accessToken, $shopCipher, $transport);
    }
}
