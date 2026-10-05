<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Dane firmy po NIP:
 *  - GUS (BIR 1.1, baza REGON) - gdy w ustawieniach jest klucz GUS_API_KEY (bezpłatny, wydaje GUS),
 *  - Biała lista VAT (API Ministerstwa Finansów) - bez klucza; tylko podatnicy VAT, limit ~100 zapytań dziennie.
 * Gdy GUS nie odpowie lub nie znajdzie firmy, próbujemy Białej listy.
 *
 * Zwraca pola formularza danych do faktury: company, address_1, postcode, city, state, country, nip, regon, source.
 */
final class CompanyLookup
{
    public const SETTING_KEY = 'GUS_API_KEY';
    private const GUS_URL = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
    private const NS = 'http://CIS/BIR/PUBL/2014/07';
    private const MF_URL = 'https://wl-api.mf.gov.pl/api/search/nip/';

    /** @var callable(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private $transport;

    public function __construct(private readonly SettingsRepository $settings, ?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    public function hasGusKey(): bool
    {
        return trim((string) $this->settings->get(self::SETTING_KEY, '')) !== '';
    }

    /**
     * @param string $source 'auto' (GUS, gdy jest klucz, potem Biała lista) | 'gus' | 'mf'
     * @return array<string,string>
     * @throws \RuntimeException z komunikatem dla użytkownika
     */
    public function byNip(string $nip, string $source = 'auto'): array
    {
        $digits = InvoiceData::normalizeNip($nip);
        if (!InvoiceData::validNip($digits)) {
            throw new \RuntimeException('To nie jest poprawny NIP (10 cyfr z prawidłową sumą kontrolną).');
        }
        $errors = [];
        if ($source !== 'mf') {
            if (!$this->hasGusKey()) {
                if ($source === 'gus') {
                    throw new \RuntimeException('Brak klucza do GUS. Wpisz go w ⚙ obok przycisku GUS albo użyj „Biała lista VAT”.');
                }
            } else {
                try {
                    return $this->gus($digits);
                } catch (\RuntimeException $e) {
                    $errors[] = 'GUS: ' . $e->getMessage();
                    if ($source === 'gus') { throw new \RuntimeException(implode(' ', $errors)); }
                }
            }
        }
        try {
            return $this->mf($digits);
        } catch (\RuntimeException $e) {
            $errors[] = ($errors ? 'Biała lista VAT: ' : '') . $e->getMessage();
            throw new \RuntimeException(implode(' ', $errors));
        }
    }

    // ---------------------------------------------------------------- GUS BIR 1.1

    /** @return array<string,string> */
    private function gus(string $nip): array
    {
        $key = trim((string) $this->settings->get(self::SETTING_KEY, ''));
        $login = $this->soap('Zaloguj', '<ns:Zaloguj><ns:pKluczUzytkownika>' . htmlspecialchars($key, ENT_XML1) . '</ns:pKluczUzytkownika></ns:Zaloguj>');
        $sid = trim(self::result($login, 'Zaloguj'));
        if ($sid === '') {
            throw new \RuntimeException('GUS odrzucił klucz (sprawdź klucz w ustawieniach).');
        }
        try {
            $resp = $this->soap('DaneSzukajPodmioty',
                '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania><dat:Nip>' . $nip . '</dat:Nip></ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>', $sid);
            $xml = html_entity_decode(self::result($resp, 'DaneSzukajPodmioty'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            try {
                $this->soap('Wyloguj', '<ns:Wyloguj><ns:pIdentyfikatorSesji>' . htmlspecialchars($sid, ENT_XML1) . '</ns:pIdentyfikatorSesji></ns:Wyloguj>', $sid);
            } catch (\Throwable) {
            }
        }
        return self::parseGus($xml, $nip);
    }

    /** Wynik DaneSzukajPodmioty (XML <root><dane>…</dane></root>) -> pola formularza. @return array<string,string> */
    public static function parseGus(string $xml, string $nip): array
    {
        if (trim($xml) === '' || !preg_match('#<dane>(.*?)</dane>#s', $xml, $m)) {
            throw new \RuntimeException('Nie znaleziono firmy o tym NIP.');
        }
        $f = static function (string $tag) use ($m): string {
            return preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $m[1], $x) ? trim(html_entity_decode($x[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
        };
        if ($f('ErrorCode') !== '') {
            throw new \RuntimeException($f('ErrorCode') === '4' ? 'Nie znaleziono firmy o tym NIP.' : ('GUS: ' . ($f('ErrorMessagePl') ?: 'błąd ' . $f('ErrorCode'))));
        }
        $number = $f('NrNieruchomosci') . ($f('NrLokalu') !== '' ? '/' . $f('NrLokalu') : '');
        $street = $f('Ulica') !== '' ? $f('Ulica') : $f('Miejscowosc');   // wieś bez ulic: „Testowo 12”
        $postCity = $f('MiejscowoscPoczty') !== '' ? $f('MiejscowoscPoczty') : $f('Miejscowosc');
        $kod = $f('KodPocztowy');
        if (preg_match('/^\d{5}$/', $kod)) { $kod = substr($kod, 0, 2) . '-' . substr($kod, 2); }
        return [
            'company'   => $f('Nazwa'),
            'address_1' => trim($street . ' ' . $number),
            'postcode'  => $kod,
            'city'      => $postCity,
            'state'     => mb_strtolower($f('Wojewodztwo')),
            'country'   => 'PL',
            'nip'       => InvoiceData::formatNip($nip),
            'regon'     => $f('Regon'),
            'closed'    => $f('DataZakonczeniaDzialalnosci'),
            'source'    => 'GUS',
        ];
    }

    private function soap(string $action, string $body, ?string $sid = null): string
    {
        $env = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="' . self::NS . '" xmlns:dat="' . self::NS . '/DataContract">'
            . '<soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">'
            . '<wsa:To>' . self::GUS_URL . '</wsa:To><wsa:Action>' . self::NS . '/IUslugaBIRzewnPubl/' . $action . '</wsa:Action>'
            . '</soap:Header><soap:Body>' . $body . '</soap:Body></soap:Envelope>';
        $headers = ['Content-Type' => 'application/soap+xml; charset=utf-8'];
        if ($sid !== null) { $headers['sid'] = $sid; }
        [$status, $resp] = ($this->transport)('POST', self::GUS_URL, $headers, $env);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException($status === 0 ? 'brak połączenia z GUS.' : 'GUS odpowiedział błędem ' . $status . '.');
        }
        return $resp;
    }

    /** Odpowiedź GUS przychodzi jako MTOM (multipart) - wyciągamy treść <…Result>. */
    private static function result(string $resp, string $action): string
    {
        return preg_match('#<' . $action . 'Result>(.*?)</' . $action . 'Result>#s', $resp, $m) ? $m[1] : '';
    }

    // ---------------------------------------------------------------- Biała lista VAT (MF)

    /** @return array<string,string> */
    private function mf(string $nip): array
    {
        [$status, $resp] = ($this->transport)('GET', self::MF_URL . $nip . '?date=' . date('Y-m-d'), ['Accept' => 'application/json'], null);
        $json = json_decode($resp, true);
        if ($status === 0) {
            throw new \RuntimeException('Brak połączenia z Białą listą VAT.');
        }
        if ($status === 429) {
            throw new \RuntimeException('Wyczerpany dzienny limit zapytań Białej listy VAT — dodaj klucz GUS.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException((string) ($json['message'] ?? ('Biała lista VAT odpowiedziała błędem ' . $status . '.')));
        }
        return self::parseMf(is_array($json) ? $json : [], $nip);
    }

    /** @return array<string,string> */
    public static function parseMf(array $json, string $nip): array
    {
        $s = $json['result']['subject'] ?? null;
        if (!is_array($s)) {
            throw new \RuntimeException('Nie znaleziono firmy o tym NIP na Białej liście VAT (tylko czynni i zwolnieni podatnicy VAT). Uzupełnij dane ręcznie albo dodaj klucz GUS.');
        }
        $addr = trim((string) ($s['workingAddress'] ?? '')) ?: trim((string) ($s['residenceAddress'] ?? ''));
        $street = $addr; $postcode = ''; $city = '';
        // „TESTOWA 77, 00-001 WARSZAWA” albo „UL. X 1/2, 00-001 WARSZAWA”
        if (preg_match('/^(.*),\s*(\d{2}-\d{3})\s+(.+)$/u', $addr, $m)) {
            [$street, $postcode, $city] = [trim($m[1]), $m[2], trim($m[3])];
        }
        return [
            'company'   => trim((string) ($s['name'] ?? '')),
            'address_1' => self::nice($street),
            'postcode'  => $postcode,
            'city'      => self::nice($city),
            'state'     => '',
            'country'   => 'PL',
            'nip'       => InvoiceData::formatNip($nip),
            'regon'     => (string) ($s['regon'] ?? ''),
            'closed'    => (string) ($s['statusVat'] ?? '') === 'Niezarejestrowany' ? 'niezarejestrowany VAT' : '',
            'source'    => 'Biała lista VAT',
        ];
    }

    /** „UL. TESTOWA 77” -> „ul. Testowa 77” (Biała lista zwraca wielkie litery). */
    private static function nice(string $s): string
    {
        if ($s === '' || mb_strtoupper($s) !== $s) { return $s; }
        $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE);
        return preg_replace_callback('/\b(Ul|Al|Os|Pl)\.\s/u', static fn($m) => mb_strtolower($m[0]), $s) ?? $s;
    }

    /** @return array{0:int,1:string} */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (\Pase\Support\Demo::on()) {
            return [0, ''];
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'Veless/1.0',
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            return [0, ''];
        }
        \Pase\Support\UsageStats::api(str_contains($url, 'stat.gov.pl') ? 'gus' : 'mf_whitelist', $status);
        return [$status, (string) $resp];
    }
}
