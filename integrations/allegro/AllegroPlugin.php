<?php
declare(strict_types=1);

namespace PasePlugin\Allegro;

use PDO;
use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\ProvidesTemplates;
use Pase\Plugin\PluginManifest;
use Pase\Repository\IntegrationRepository;

/**
 * Wtyczka Allegro — SAMOWYSTARCZALNA (logika API w AllegroClient w tym katalogu).
 *
 * Specyfika: Allegro używa OAuth 2.0, a tokeny trzymamy w tabeli `integrations`.
 * Dane aplikacji (client_id/secret, redirect_uri, env) pochodzą z .env, nie z konta
 * w integration_accounts. Dlatego klient potrzebuje PDO (do tokenów) - budujemy go
 * przez makeClient($pdo, $config). Manifest/testConnection pozostają, by wtyczka była
 * widoczna na liście Wtyczki i w panelu integracji.
 */
final class AllegroPlugin extends AbstractPlugin implements ProvidesTemplates
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'allegro',
            name: 'Allegro',
            version: '1.0.0',
            author: 'Veless',
            // OAuth + (docelowo) źródło zamówień i wystawianie ofert.
            capabilities: [Capability::ORDER_SOURCE],
            fields: [
                // Allegro konfiguruje się przez .env (OAuth), nie przez pola konta -
                // dlatego tu tylko informacja, a połączenie robisz przyciskiem w panelu.
                ['key' => 'env', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'sandbox',
                 'options' => ['sandbox' => 'Sandbox (testy)', 'production' => 'Produkcja'],
                 'help' => 'Ustawiane w .env (ALLEGRO_ENV). Pole poglądowe.'],
            ],
            color: '#ff5a00',
            icon: '🛒',
            // Logo kanału na liście zamówień (Services\OrderChannelIcons).
            logo: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#ff5a00"/><text x="12" y="17.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="17" font-weight="700" fill="#fff">a</text></svg>',
            multiple: false,
            description: 'Logowanie OAuth, sync stanów do ofert, (wkrótce) wystawianie ofert.',
            category: Capability::CAT_MARKETPLACE
        );
    }

    /**
     * Test połączenia bez PDO nie jest możliwy (token w bazie) - zwraca podpowiedź.
     * Realny test robi panel przez makeClient()->checkConnection().
     */
    public function testConnection(): array
    {
        return ['ok' => false, 'message' => 'Użyj „Testuj połączenie" w panelu integracji Allegro (wymaga połączenia OAuth).'];
    }

    /** Szablony udostępniane do huba „Szablony" (Konfiguracja → Szablony). */
    public function templates(): array
    {
        return [[
            'name' => 'Opis ofert Allegro',
            'url'  => 'allegro_templates.php',
            'desc' => 'Szablony opisu w stylu Allegro (moduły Tekst / Grafika) przypisane do kategorii.',
            'icon' => '🧩',
        ]];
    }

    /**
     * Buduje klienta API Allegro związanego z bazą (tokeny) i configiem z .env.
     * @param array<string,mixed> $config sekcja 'allegro' z config/config.php
     */
    public static function makeClient(PDO $pdo, array $config): AllegroClient
    {
        return new AllegroClient($config, new IntegrationRepository($pdo));
    }
}
