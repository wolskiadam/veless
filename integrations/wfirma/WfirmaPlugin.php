<?php
declare(strict_types=1);

namespace PasePlugin\Wfirma;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\Invoicing;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka wFirma (księgowość / faktury) — SAMOWYSTARCZALNA. Cała logika API
 * mieszka w tej wtyczce (WfirmaClient), rdzeń woła ją wyłącznie przez kontrakt
 * Invoicing z rejestru wtyczek.
 *
 * Konfiguracja per konto (integration_accounts.config):
 *   base_url, access_key, secret_key, app_key, company_id.
 */
final class WfirmaPlugin extends AbstractPlugin implements Invoicing
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'wfirma',
            name: 'wFirma',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::INVOICING],
            fields: [
                ['key' => 'base_url', 'label' => 'Adres API', 'type' => 'url', 'default' => 'https://api2.wfirma.pl',
                 'help' => 'Zwykle https://api2.wfirma.pl — zmień tylko jeśli używasz innego środowiska.'],
                ['key' => 'access_key', 'label' => 'Access key', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'wFirma → Ustawienia → Klucze API (accessKey).'],
                ['key' => 'secret_key', 'label' => 'Secret key', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Sekret pary kluczy API (secretKey).'],
                ['key' => 'app_key', 'label' => 'App key', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Klucz aplikacji (appKey) z panelu wFirma.'],
                ['key' => 'company_id', 'label' => 'ID firmy (company_id)', 'type' => 'text', 'required' => true,
                 'help' => 'Numeryczne ID firmy w wFirma, na której wystawiane są dokumenty.'],
                ['key' => 'default_type', 'label' => 'Domyślny typ dokumentu', 'type' => 'select', 'default' => 'normal',
                 'options' => [
                     'normal' => 'Faktura VAT',
                     'proforma' => 'Proforma',
                     'receipt' => 'Paragon niefiskalny',
                     'receipt_fiscal' => 'Paragon fiskalny',
                 ],
                 'help' => 'Typ używany, gdy automatyzacja nie poda innego.'],
            ],
            color: '#1e7f4f',
            icon: '🧾',
            multiple: false,
            description: 'Wystawianie faktur VAT, proform i paragonów w wFirma na dane nabywcy z zamówienia.',
            category: Capability::CAT_ACCOUNTING
        );
    }

    private function client(): WfirmaClient
    {
        return new WfirmaClient($this->config);
    }

    public function testConnection(): array
    {
        $r = $this->client()->testConnection();
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? '')];
    }

    /**
     * Wystawia dokument sprzedaży. Gdy $type nie podano wprost, używa domyślnego
     * typu z konfiguracji konta (default_type).
     *
     * @return array{ok:bool,message:string,document_id?:string,pdf_url?:string}
     */
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array
    {
        if ($type === '') {
            $type = (string) $this->cfg('default_type', 'normal');
        }

        $r = $this->client()->issueDocument($contractor, $positions, $type, $options);
        return [
            'ok'          => (bool) ($r['ok'] ?? false),
            'message'     => (string) ($r['message'] ?? ''),
            'document_id' => (string) ($r['document_id'] ?? ''),
            'pdf_url'     => (string) ($r['pdf_url'] ?? ''),
        ];
    }
}
