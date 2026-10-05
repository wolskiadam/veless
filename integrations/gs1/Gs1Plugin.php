<?php
declare(strict_types=1);

namespace PasePlugin\Gs1;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka GS1 Polska (MojeGS1) - kody EAN/GTIN produktów.
 *
 * Wtyczka zawiera klienta API (Gs1Client) i dane konta. Strona Magazyn → GS1, kopia kart (gs1_products)
 * i dopasowanie do produktów CRM są w rdzeniu (Pase\Services\Gs1) i sięgają po klienta przez
 * IntegrationClient::for('gs1', ...). Wyłączenie albo usunięcie wtyczki ukrywa całą integrację.
 */
final class Gs1Plugin extends AbstractPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'gs1',
            name: 'GS1 Polska (MojeGS1)',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [],
            fields: [
                ['key' => 'login', 'label' => 'Login API', 'type' => 'text', 'required' => true,
                 'help' => 'mojegs1.pl → Moje dane → Profile użytkowników → Zmień przy użytkowniku → Menu → Zmień dane api. Każde kliknięcie tworzy nowe dane.'],
                ['key' => 'password', 'label' => 'Hasło API', 'type' => 'password', 'required' => true, 'secret' => true],
                ['key' => 'prefix', 'label' => 'Prefiks firmy GS1', 'type' => 'text',
                 'help' => 'Początek Twoich numerów (zwykle 7–10 cyfr, np. 590123456). Potrzebny do proponowania kolejnego wolnego GTIN. Pusty = CRM podpowie go z Twoich kart.'],
                ['key' => 'card_url', 'label' => 'Adres karty w MojeGS1', 'type' => 'url',
                 'help' => 'Dla linków „otwórz w GS1” przy kodach EAN. Otwórz dowolny produkt w MojeGS1, skopiuj adres z paska przeglądarki i wpisz go tu, zamieniając numer GTIN na {gtin}. Puste = linki do wyszukiwarki Verified by GS1.'],
                ['key' => 'push_woo', 'label' => 'Wysyłaj EAN do WooCommerce', 'type' => 'select', 'default' => '1',
                 'options' => ['1' => 'Tak — po nadaniu numeru wpisz go też w sklepie', '0' => 'Nie — tylko w CRM']],
            ],
            color: '#f26334',
            icon: '🏷️',
            multiple: false,
            description: 'Kody EAN z GS1 Polska: lista Twoich kart z MojeGS1, dopasowanie do produktów i nadawanie nowych numerów (tylko po kliknięciu).',
            category: Capability::CAT_OTHER
        );
    }

    public function testConnection(): array
    {
        $prefix = preg_replace('/\D/', '', (string) $this->cfg('prefix', '')) ?? '';
        if ($prefix !== '' && (strlen($prefix) < 6 || strlen($prefix) > 11)) {
            return ['ok' => false, 'message' => 'Prefiks firmy GS1 ma zwykle 7–10 cyfr (np. 590123456). Sprawdź go w MojeGS1.'];
        }
        return $this->client()->test();
    }

    public function client(): Gs1Client
    {
        return new Gs1Client($this->config);
    }
}
