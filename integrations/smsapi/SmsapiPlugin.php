<?php
declare(strict_types=1);

namespace PasePlugin\Smsapi;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\Sms;
use Pase\Plugin\PluginManifest;
use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Wtyczka SMSAPI.pl - wysyłka SMS (np. z automatyzacji „Wyślij SMS").
 *
 * Konfiguracja: token OAuth z panelu SMSAPI (Ustawienia API → Tokeny API, uprawnienie „SMS"),
 * nazwa nadawcy (musi być zatwierdzona w SMSAPI; pusta = domyślna z konta), tryb testowy.
 * API: POST https://api.smsapi.pl/sms.do (application/x-www-form-urlencoded, format=json).
 */
final class SmsapiPlugin extends AbstractPlugin implements Sms
{
    private const API = 'https://api.smsapi.pl';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'smsapi',
            name: 'SMSAPI',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::SMS],
            fields: [
                ['key' => 'token', 'label' => 'Token API', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Panel SMSAPI → Ustawienia API → Tokeny API. Zaznacz uprawnienie „SMS".'],
                ['key' => 'sender', 'label' => 'Nazwa nadawcy', 'type' => 'text',
                 'help' => 'Pole nadawcy zatwierdzone w SMSAPI (np. MojSklep). Puste = domyślne z konta.'],
                ['key' => 'test', 'label' => 'Tryb testowy', 'type' => 'select', 'default' => '0',
                 'options' => ['0' => 'Nie — wysyłaj naprawdę', '1' => 'Tak — tylko sprawdzaj (SMS nie wychodzi, bez opłat)'],
                 'help' => 'W trybie testowym SMSAPI przyjmuje wiadomość, ale jej nie wysyła.'],
            ],
            color: '#0a7cff',
            icon: '💬',
            multiple: false,
            description: 'Wysyłka SMS do klientów przez SMSAPI.pl — np. z automatyzacji po nadaniu lub doręczeniu paczki.',
            category: Capability::CAT_OTHER
        );
    }

    public function testConnection(): array
    {
        $res = Http::request('GET', self::API . '/profile', $this->headers());
        if ($res->isSuccess()) {
            $p = $res->json();
            return ['ok' => true, 'message' => 'Połączono z SMSAPI' . (isset($p['points']) ? ' — punkty: ' . $p['points'] : '') . '.'];
        }
        return ['ok' => false, 'message' => 'SMSAPI odrzuciło token (kod ' . $res->status . ').'];
    }

    public function sendSms(string $phone, string $text): array
    {
        $params = [
            'to'       => $phone,
            'message'  => $text,
            'format'   => 'json',
            'encoding' => 'utf-8',
        ];
        $sender = trim((string) $this->cfg('sender', ''));
        if ($sender !== '') {
            $params['from'] = $sender;
        }
        if ((string) $this->cfg('test', '0') === '1') {
            $params['test'] = '1';
        }
        $res = Http::request('POST', self::API . '/sms.do',
            $this->headers() + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($params));
        Logger::apiResponse('smsapi', 'POST', '/sms.do', $res->status, 'SMS do ' . substr($phone, 0, 5) . '…');
        $j = $res->json();
        if ($res->isSuccess() && empty($j['error'])) {
            return ['ok' => true, 'message' => 'Wysłano SMS' . ((string) $this->cfg('test', '0') === '1' ? ' (tryb testowy)' : '') . '.',
                    'id' => (string) ($j['list'][0]['id'] ?? '')];
        }
        return ['ok' => false, 'message' => 'SMSAPI: ' . (string) ($j['message'] ?? ('kod ' . $res->status))];
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . trim((string) $this->cfg('token', ''))];
    }
}
