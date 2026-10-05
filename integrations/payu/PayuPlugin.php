<?php
declare(strict_types=1);

namespace PasePlugin\Payu;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka PayU - saldo sklepu i zlecanie wypłat (Payouts API, https://developers.payu.com/europe/pl/docs/payouts/payouts-api/).
 *
 * Wtyczka daje klienta API (PayuClient) i dane konta. Stronę System → PayU i historię wypłat (payu_payouts) ma rdzeń
 * w Pase\Services\PayuPayouts i sięga po klienta przez IntegrationClient::for('payu', ...). Bez wtyczki strona znika z menu.
 */
final class PayuPlugin extends AbstractPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'payu',
            name: 'PayU (saldo i wypłaty)',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [],
            fields: [
                ['key' => 'client_id', 'label' => 'Id punktu płatności (pos_id / client_id)', 'type' => 'text', 'required' => true,
                 'help' => 'Panel PayU → Płatności elektroniczne → Moje sklepy → Punkty płatności → wybrany punkt → Klucze konfiguracyjne: „Protokół OAuth - client_id”.'],
                ['key' => 'client_secret', 'label' => 'Klucz OAuth (client_secret)', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Z tego samego miejsca: „Protokół OAuth - client_secret”.'],
                ['key' => 'second_key', 'label' => 'Drugi klucz (MD5)', 'type' => 'password', 'secret' => true,
                 'help' => 'Z tego samego miejsca: „Drugi klucz (MD5)”. Opcjonalny: gdy jest wpisany, CRM odrzuca powiadomienia PayU bez poprawnego podpisu.'],
                ['key' => 'shop_id', 'label' => 'Id sklepu (shopId)', 'type' => 'text', 'required' => true,
                 'help' => 'Panel PayU → Płatności elektroniczne → Moje sklepy → dane sklepu (8 znaków, np. SHOP1234). To nie jest id punktu płatności.'],
                ['key' => 'environment', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcja (prawdziwe pieniądze)', 'sandbox' => 'Sandbox (testowe konto PayU)']],
            ],
            color: '#a6c307',
            icon: '💰',
            multiple: true,   // każdy sklep PayU to osobne konto (własne klucze punktu płatności i shopId)
            description: 'Saldo sklepów PayU i zlecanie wypłat na konto bankowe zapisane w PayU (tylko po potwierdzeniu). Masz kilka sklepów? Dodaj konto dla każdego.',
            category: Capability::CAT_ACCOUNTING
        );
    }

    public function testConnection(): array
    {
        return $this->client()->test();
    }

    public function client(): PayuClient
    {
        return new PayuClient($this->config);
    }
}
