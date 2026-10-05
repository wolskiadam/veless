<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Punkt odbioru zamówienia (paczkomat InPost, automat/punkt ORLEN Paczka, punkt DPD, Allegro One...).
 *
 *  - Allegro: checkout-form ma go wprost w delivery.pickupPoint {id, name, description, address},
 *  - WooCommerce: każda wtyczka wysyłkowa zapisuje go po swojemu w meta zamówienia albo
 *    w meta metody wysyłki - szukamy po znanych kluczach, a potem po nazwach z „point"/„paczkomat".
 */
final class PickupPoint
{
    /** Znane klucze meta z ID punktu (WP Desk InPost, BLPaczka, Apaczka, Furgonetka, Sendit, ORLEN...). */
    private const KNOWN_KEYS = [
        '_paczkomat_id', 'paczkomat_id', '_paczkomat', 'paczkomat', '_inpost_parcel_locker', 'inpost_parcel_locker',
        '_parcel_machine_id', 'parcel_machine_id', 'parcel_locker', '_parcel_locker', 'inpost_point',
        '_blpaczka_point', 'blpaczka_point', 'blpaczka_point_id', '_blpaczka_point_id',
        'apaczka_delivery_point', '_apaczka_point_id', 'apaczka_point', 'apaczka_supplier_point',
        '_furgonetka_point', 'furgonetka_point', '_furgonetka_point_code', 'furgonetka_point_code',
        '_orlen_point', 'orlen_point', '_orlen_paczka_point', 'orlen_paczka_point', 'paczka_w_ruchu',
        '_pickup_point_id', 'pickup_point_id', 'pickup_point', '_pickup_point', 'point_id', '_point_id', 'point_code',
        '_dpd_pickup_point', 'dpd_pickup', 'sendit_point', '_sendit_point',
    ];

    /**
     * @param array<string,mixed> $payload zamówienie (checkout-form Allegro albo zamówienie Woo)
     * @param array<string,mixed>|null $override punkt poprawiony ręcznie w CRM (ma pierwszeństwo)
     * @return array{id:string,name:string,street:string,postcode:string,city:string,line:string}|null
     */
    public static function fromOrder(array $payload, ?array $override = null): ?array
    {
        if (is_array($override) && trim((string) ($override['id'] ?? '')) !== '') {
            return self::shape($override);
        }

        $pp = $payload['delivery']['pickupPoint'] ?? null;
        if (is_array($pp) && !empty($pp['id'])) {
            $a = $pp['address'] ?? [];
            return self::shape([
                'id'       => $pp['id'],
                'name'     => $pp['name'] ?? ($pp['description'] ?? ''),
                'street'   => $a['street'] ?? '',
                'postcode' => $a['zipCode'] ?? '',
                'city'     => $a['city'] ?? '',
            ]);
        }

        // WooCommerce: meta zamówienia, potem meta metody wysyłki.
        $metas = [];
        foreach ($payload['meta_data'] ?? [] as $m) {
            $metas[] = $m;
        }
        foreach ($payload['shipping_lines'] ?? [] as $sl) {
            foreach ($sl['meta_data'] ?? [] as $m) {
                $metas[] = $m;
            }
        }
        // Klucze porównujemy bez wielkości liter i znaków innych niż litery/cyfry:
        // „_furgonetkaPoint", „furgonetka_point" i „Punkt odbioru" trafiają tak samo.
        $byKey = [];
        foreach ($metas as $m) {
            if (!is_array($m)) {
                continue;
            }
            $k = self::normKey((string) ($m['key'] ?? ''));
            $v = $m['value'] ?? null;
            if ($k !== '' && $v !== null && $v !== '' && !isset($byKey[$k])) {
                $byKey[$k] = $v;
            }
        }
        foreach (self::KNOWN_KEYS as $k) {
            $nk = self::normKey($k);
            if (isset($byKey[$nk]) && ($p = self::fromMetaValue($byKey[$nk])) !== null) {
                return self::withDetails($p, $byKey, $nk);
            }
        }
        foreach ($byKey as $k => $v) {
            if (self::isPointIdKey($k) && ($p = self::fromMetaValue($v, true)) !== null) {
                return self::withDetails($p, $byKey, $k);
            }
        }

        // Część wtyczek (np. „Punkt odbioru: BYD117M (INPOST)") wpisuje punkt tekstem
        // w adres dostawy albo w opis metody wysyłki zamiast w osobne meta.
        $sh = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $texts = [];
        foreach (['company', 'first_name', 'last_name', 'address_1', 'address_2'] as $f) {
            $texts[] = ['shipping', $f, (string) ($sh[$f] ?? '')];
        }
        foreach ($metas as $m) {
            if (is_array($m) && is_scalar($m['value'] ?? null)) {
                $texts[] = ['meta', '', (string) $m['value']];
            }
        }
        foreach ($payload['shipping_lines'] ?? [] as $sl) {
            $texts[] = ['meta', '', (string) ($sl['method_title'] ?? '')];
        }
        foreach ($texts as [$src, $field, $text]) {
            if (!preg_match('/(?:punkt(?:u)?\s+odbioru|paczkomat|punkt|automat|parcel\s*locker|pickup\s*point)\s*(?:nr\.?\s*)?[:#-]\s*([A-Z0-9][A-Z0-9_-]{2,19})(?:\s*\(([^)]*)\))?/iu', $text, $mm)
                || !preg_match('/\d/', $mm[1])) {
                continue;
            }
            $p = ['id' => $mm[1], 'name' => trim($mm[2] ?? '')];
            if ($src === 'shipping') {
                // Pozostałe pola adresu dostawy to adres punktu.
                $rest = [];
                foreach (['company', 'first_name', 'last_name', 'address_1', 'address_2'] as $f) {
                    $val = trim((string) ($sh[$f] ?? ''));
                    if ($f !== $field && $val !== '') {
                        $rest[] = $val;
                    }
                }
                $p['street'] = implode(', ', $rest);
                $p['postcode'] = (string) ($sh['postcode'] ?? '');
                $p['city'] = (string) ($sh['city'] ?? '');
            }
            return self::shape($p);
        }
        return null;
    }

    private static function normKey(string $k): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
    }

    /** Czy klucz (znormalizowany) wygląda na kod punktu - a nie na jego nazwę, adres albo przewoźnika. */
    private static function isPointIdKey(string $k): bool
    {
        if (!preg_match('/paczkomat|parcellocker|parcelmachine|locker|pickuppoint|punkt|point(?!s)/', $k)) {
            return false;
        }
        if (preg_match('/points|reward|loyal|earn|redeem|lat$|lng$|lon$|url$|html|map$/', $k)) {
            return false;
        }
        return !self::detailOf($k);
    }

    /** Rodzaj pola ze szczegółami punktu po nazwie klucza (null = to nie szczegół). */
    private static function detailOf(string $k): ?string
    {
        return match (true) {
            (bool) preg_match('/(street|ulica|address\d?|adres)$/', $k) => 'street',
            (bool) preg_match('/(city|miasto|town)$/', $k) => 'city',
            (bool) preg_match('/(postcode|postalcode|zipcode|zip|kodpocztowy)$/', $k) => 'postcode',
            (bool) preg_match('/(name|nazwa|desc|description|opis|label|title)$/', $k) => 'name',
            (bool) preg_match('/(service|carrier|courier|operator|provider|type|typ|przewoznik)$/', $k) => 'carrier',
            default => null,
        };
    }

    /**
     * Uzupełnia punkt o nazwę/adres z sąsiednich meta tej samej wtyczki
     * (np. „_furgonetkaPoint" + „_furgonetkaPointName" + „_furgonetkaService").
     */
    private static function withDetails(array $p, array $byKey, string $idKey): array
    {
        // Wspólny przedrostek: klucz ID bez końcówki „id"/„code"/„point"...
        $prefix = (string) preg_replace('/(pointid|pointcode|point|id|code|kod)$/', '', $idKey);
        $prefix = strlen($prefix) >= 3 ? $prefix : $idKey;
        $carrier = '';
        foreach ($byKey as $k => $v) {
            if ($k === $idKey || !str_starts_with($k, $prefix) || !is_scalar($v) || ($kind = self::detailOf($k)) === null) {
                continue;
            }
            $v = trim((string) $v);
            if ($kind === 'carrier') {
                $carrier = $carrier !== '' ? $carrier : $v;
            } elseif (($p[$kind] ?? '') === '') {
                $p[$kind] = $v;
            }
        }
        // Nazwa bywa pełnym adresem („SM Komunalni, Fałata 2, 85-309 Bydgoszcz") - rozbijamy.
        if (($p['street'] ?? '') === '' && preg_match('/^(.*?),?\s*(\d{2}-\d{3})\s+(.+)$/u', (string) ($p['name'] ?? ''), $mm)) {
            $parts = array_map('trim', explode(',', $mm[1]));
            $p['street'] = array_pop($parts) ?? '';
            $p['name'] = implode(', ', $parts);
            $p['postcode'] = ($p['postcode'] ?? '') ?: $mm[2];
            $p['city'] = ($p['city'] ?? '') ?: $mm[3];
        }
        if (($p['name'] ?? '') === '' && $carrier !== '') {
            $p['name'] = strtoupper($carrier);
        }
        return self::shape($p);
    }

    /** Wartość meta: samo ID („KRA01M", „963253") albo obiekt/tablica z polami punktu. */
    private static function fromMetaValue(mixed $v, bool $strict = false): ?array
    {
        if (is_string($v) && str_starts_with(trim($v), '{')) {
            $v = json_decode($v, true) ?? $v;
        }
        if (is_array($v)) {
            // Klucze bez przedrostka wtyczki: Apaczka zapisuje „apm_access_point_id", „apm_postal_code"...
            $a = [];
            foreach ($v as $k => $val) {
                $a[(string) preg_replace('/^apm_/', '', strtolower((string) $k))] = $val;
            }
            $id = null;
            foreach (['access_point_id', 'point_id', 'id', 'code', 'foreign_access_point_id'] as $k) {
                if (is_scalar($a[$k] ?? null) && trim((string) $a[$k]) !== '') {
                    $id = $a[$k];
                    break;
                }
            }
            $name = $a['description'] ?? $a['label'] ?? ($id !== null ? ($a['name'] ?? '') : '');
            $id ??= $a['name'] ?? null;
            if (!is_scalar($id) || trim((string) $id) === '') {
                return null;
            }
            $carrier = $a['supplier'] ?? $a['carrier'] ?? $a['courier'] ?? '';
            if (is_scalar($carrier) && trim((string) $carrier) !== '') {
                $name = trim(is_scalar($name) ? (string) $name : '');
                $name = $name === '' ? strtoupper((string) $carrier) : $name . ' (' . strtoupper((string) $carrier) . ')';
            }
            return self::shape([
                'id' => $id, 'name' => is_scalar($name) ? $name : '',
                'street' => $a['street'] ?? $a['address'] ?? '',
                'postcode' => $a['postcode'] ?? $a['post_code'] ?? $a['postal_code'] ?? $a['zip'] ?? '',
                'city' => $a['city'] ?? '',
            ]);
        }
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        // „KRA01M", „963253", „POP-WAW123" albo tekst z kodem („Paczkomat KRA01M, ul. ...") - kod ma cyfrę.
        if (preg_match('/(?<![\w-])((?=[A-Z0-9_-]*\d)[A-Z0-9][A-Z0-9_-]{2,19})(?![\w-])/iu', $s, $mm)
            // Klucz dobrany tylko po nazwie: sama liczba musi mieć co najmniej 5 cyfr (np. punkt ORLEN „963253").
            && (!$strict || preg_match('/[A-Z]/i', $mm[1]) || strlen($mm[1]) >= 5)) {
            $rest = trim(preg_replace('/\s+/', ' ', str_replace([$mm[1], '(', ')'], ' ', $s)) ?? '', " \t,;:|-");
            return self::shape(['id' => $mm[1], 'name' => $rest]);
        }
        return null;
    }

    private static function shape(array $p): array
    {
        $out = [
            'id'       => trim((string) ($p['id'] ?? '')),
            'name'     => trim((string) ($p['name'] ?? '')),
            'street'   => trim((string) ($p['street'] ?? '')),
            'postcode' => trim((string) ($p['postcode'] ?? '')),
            'city'     => trim((string) ($p['city'] ?? '')),
        ];
        $out['line'] = trim($out['street'] . ', ' . trim($out['postcode'] . ' ' . $out['city']), ' ,');
        return $out;
    }
}
