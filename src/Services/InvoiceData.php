<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Dane do faktury zamówienia: oryginał ze sklepu (Woo: billing + meta NIP, Allegro: invoice / buyer)
 * i lokalna poprawka z CRM (kolumna woo_orders.local_invoice, JSON) - poprawka ma pierwszeństwo.
 *
 * Poprawka zapisuje się tylko w CRM (nie wraca do sklepu). Używają jej: podgląd zamówienia,
 * wystawianie faktury w wFirma, wskaźnik „faktura” na liście zamówień i szablony wydruku.
 */
final class InvoiceData
{
    /** Pola formularza (i klucze w local_invoice). */
    public const FIELDS = ['name', 'company', 'address_1', 'postcode', 'city', 'state', 'country', 'nip'];
    private const LIMITS = ['name' => 160, 'company' => 200, 'address_1' => 200, 'postcode' => 12, 'city' => 100,
        'state' => 60, 'country' => 2, 'nip' => 20];

    /**
     * Efektywne dane do faktury.
     * @return array{wants:bool,requested:?bool,name:string,company:string,address_1:string,postcode:string,city:string,
     *               state:string,country:string,nip:string,edited:bool,email:string,phone:string}
     */
    public static function resolve(array $payload, ?string $localJson = null): array
    {
        $billing = self::originalBilling($payload);
        $requested = self::requested($payload);
        $data = [
            'requested' => $requested,
            'name'      => trim((string) ($billing['first_name'] ?? '') . ' ' . (string) ($billing['last_name'] ?? '')),
            'company'   => trim((string) ($billing['company'] ?? '')),
            'address_1' => trim((string) ($billing['address_1'] ?? '') . ' ' . (string) ($billing['address_2'] ?? '')),
            'postcode'  => trim((string) ($billing['postcode'] ?? '')),
            'city'      => trim((string) ($billing['city'] ?? '')),
            'state'     => trim((string) ($billing['state'] ?? '')),
            'country'   => strtoupper(trim((string) ($billing['country'] ?? ''))) ?: 'PL',
            'nip'       => trim((string) ($billing['nip'] ?? '')),
            'email'     => (string) ($billing['email'] ?? ''),
            'phone'     => (string) ($billing['phone'] ?? ''),
            'edited'    => false,
        ];
        $data['wants'] = $requested ?? ($data['nip'] !== '');

        $local = self::decodeLocal($localJson);
        if ($local !== null) {
            foreach (self::FIELDS as $f) {
                $data[$f] = (string) ($local[$f] ?? '');
            }
            $data['country'] = $data['country'] !== '' ? $data['country'] : 'PL';
            $data['wants'] = (bool) ($local['wants'] ?? false);
            $data['edited'] = true;
        }
        return $data;
    }

    /**
     * Dane w kształcie billing Woo (+ nip) - dla wFirma i szablonów wydruku.
     * @return array<string,string>
     */
    public static function billing(array $payload, ?string $localJson = null): array
    {
        $local = self::decodeLocal($localJson);
        if ($local === null) {
            return self::originalBilling($payload);
        }
        $d = self::resolve($payload, $localJson);
        return [
            'first_name' => $d['name'], 'last_name' => '', 'company' => $d['company'],
            'address_1' => $d['address_1'], 'address_2' => '', 'postcode' => $d['postcode'], 'city' => $d['city'],
            'state' => $d['state'], 'country' => $d['country'], 'nip' => $d['nip'],
            'email' => $d['email'], 'phone' => $d['phone'],
        ];
    }

    /**
     * Czy klient prosi o fakturę: true / false z pól zamówienia, null = nie wiadomo.
     * Lokalna poprawka (jeśli jest) rozstrzyga.
     */
    public static function requested(array $payload, ?string $localJson = null): ?bool
    {
        $local = self::decodeLocal($localJson);
        if ($local !== null) {
            return (bool) ($local['wants'] ?? false);
        }
        if (isset($payload['invoice']) && is_array($payload['invoice']) && array_key_exists('required', $payload['invoice'])) {
            return (bool) $payload['invoice']['required'];   // Allegro
        }
        $meta = self::meta($payload);
        $requested = null;
        foreach (['_billing_invoice', 'billing_invoice', '_invoice_required', 'invoice_required'] as $key) {
            if (!isset($meta[$key])) { continue; }
            $value = strtolower($meta[$key]);
            if (in_array($value, ['1', 'yes', 'true', 'tak', 'invoice', 'faktura'], true)) { return true; }
            if (in_array($value, ['0', 'no', 'false', 'nie'], true)) { $requested = false; }
        }
        return $requested;
    }

    /** NIP z danych do faktury (poprawka CRM > sklep). */
    public static function nip(array $payload, ?string $localJson = null): string
    {
        return self::resolve($payload, $localJson)['nip'];
    }

    /**
     * Dane z formularza -> JSON do local_invoice.
     * @return array{json:string,errors:list<string>}
     */
    public static function fromForm(array $post): array
    {
        $out = ['wants' => !empty($post['wants'])];
        foreach (self::FIELDS as $f) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($post[$f] ?? '')) ?? '');
            $out[$f] = mb_substr($v, 0, self::LIMITS[$f]);
        }
        $out['country'] = strtoupper($out['country']) ?: 'PL';
        $errors = [];
        if ($out['nip'] !== '' && $out['country'] === 'PL') {
            $digits = self::normalizeNip($out['nip']);
            if (!self::validNip($digits)) {
                $errors[] = 'NIP „' . $out['nip'] . '” ma błędną sumę kontrolną — sprawdź cyfry.';
            } else {
                $out['nip'] = self::formatNip($digits);
            }
        }
        if ($out['wants'] && $out['name'] === '' && $out['company'] === '') {
            $errors[] = 'Podaj imię i nazwisko albo nazwę firmy.';
        }
        return ['json' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'errors' => $errors];
    }

    /** Nazwy pól, które różnią się między dwiema wersjami (do historii zmian - bez wartości). @return list<string> */
    public static function changedFields(?string $beforeJson, ?string $afterJson): array
    {
        $a = self::decodeLocal($beforeJson) ?? [];
        $b = self::decodeLocal($afterJson) ?? [];
        $changed = [];
        foreach (['wants', ...self::FIELDS] as $f) {
            if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) { $changed[] = $f; }
        }
        return $changed;
    }

    public static function normalizeNip(string $nip): string
    {
        $nip = strtoupper(trim($nip));
        if (str_starts_with($nip, 'PL')) { $nip = substr($nip, 2); }
        return preg_replace('/\D+/', '', $nip) ?? '';
    }

    /** Suma kontrolna polskiego NIP (wagi 6,5,7,2,3,4,5,6,7). */
    public static function validNip(string $digits): bool
    {
        if (!preg_match('/^\d{10}$/', $digits) || $digits === '0000000000') { return false; }
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) { $sum += $w[$i] * (int) $digits[$i]; }
        return $sum % 11 === (int) $digits[9];
    }

    public static function formatNip(string $digits): string
    {
        return strlen($digits) === 10 ? substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 2) : $digits;
    }

    /** @return array<string,mixed>|null */
    private static function decodeLocal(?string $json): ?array
    {
        if ($json === null || $json === '') { return null; }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }

    /** @return array<string,string> */
    private static function meta(array $payload): array
    {
        $meta = [];
        foreach ($payload['meta_data'] ?? [] as $item) {
            if (isset($item['key']) && is_scalar($item['value'] ?? null)) {
                $meta[(string) $item['key']] = trim((string) $item['value']);
            }
        }
        return $meta;
    }

    /**
     * Oryginalne dane nabywcy: Woo billing (+ NIP z meta), a dla Allegro dane do faktury albo kupujący.
     * @return array<string,string>
     */
    public static function originalBilling(array $payload): array
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        if ($billing === [] && (isset($payload['buyer']) || isset($payload['invoice']))) {
            $billing = self::allegroBilling($payload);
        }
        $meta = self::meta($payload);
        $billing['nip'] = (string) ($billing['nip'] ?? '');
        foreach (['_billing_nip', 'billing_nip', '_billing_vat', 'billing_vat'] as $key) {
            if (!empty($meta[$key])) {
                $billing['nip'] = $meta[$key];
                break;
            }
        }
        return $billing;
    }

    /** @return array<string,string> */
    private static function allegroBilling(array $p): array
    {
        $buyer = $p['buyer'] ?? [];
        $inv   = ($p['invoice']['required'] ?? false) ? ($p['invoice']['address'] ?? []) : [];
        $addr  = $inv ?: [
            'street'      => $buyer['address']['street'] ?? $p['delivery']['address']['street'] ?? '',
            'city'        => $buyer['address']['city'] ?? $p['delivery']['address']['city'] ?? '',
            'zipCode'     => $buyer['address']['postCode'] ?? $p['delivery']['address']['zipCode'] ?? '',
            'countryCode' => $buyer['address']['countryCode'] ?? $p['delivery']['address']['countryCode'] ?? 'PL',
        ];
        return [
            'first_name' => (string) ($inv['naturalPerson']['firstName'] ?? $buyer['firstName'] ?? ''),
            'last_name'  => (string) ($inv['naturalPerson']['lastName'] ?? $buyer['lastName'] ?? ''),
            'company'    => (string) ($inv['company']['name'] ?? ''),
            'nip'        => (string) ($inv['company']['taxId'] ?? ''),
            'email'      => (string) ($buyer['email'] ?? ''),
            'phone'      => (string) ($buyer['phoneNumber'] ?? ''),
            'address_1'  => (string) ($addr['street'] ?? ''),
            'postcode'   => (string) ($addr['zipCode'] ?? ''),
            'city'       => (string) ($addr['city'] ?? ''),
            'country'    => (string) ($addr['countryCode'] ?? 'PL'),
        ];
    }
}
