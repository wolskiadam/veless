<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Nazwa sposobu dostawy do pokazania klientowi (publiczna strona zamówienia).
 *
 * W CRM courier_code to nazwa ze sklepu / Allegro / wpisana ręcznie, czasem techniczna
 * („paczkomaty", „inpost_courier") i czasem z dopiskami („(ręcznie)", cena). Klient widzi
 * czystą nazwę bez ceny - koszt przesyłki to dana wewnętrzna.
 */
final class CarrierLabel
{
    /** Słowa kluczowe (małe litery) -> nazwa przewoźnika. Kolejność ma znaczenie. */
    private const CARRIERS = [
        'allegro one' => 'Allegro One',
        'allegro_one' => 'Allegro One',
        'inpost'      => 'InPost',
        'dpd'         => 'DPD',
        'dhl'         => 'DHL',
        'gls'         => 'GLS',
        'ups'         => 'UPS',
        'fedex'       => 'FedEx',
        'orlen'       => 'Orlen Paczka',
        'pocztex'     => 'Pocztex',
        'poczta'      => 'Poczta Polska',
    ];

    public static function forClient(?string $name): string
    {
        $clean = self::stripInternal((string) $name);
        if ($clean === '') {
            return '—';
        }
        $low = mb_strtolower($clean);

        if (preg_match('/paczkomat|parcel.?locker|locker/u', $low)) {
            return 'Paczkomat InPost';
        }

        // Nazwy techniczne (jedno słowo, np. „inpost_courier", „dpd", „kurier-dhl") -> po ludzku.
        if (!preg_match('/\s/u', $clean)) {
            $carrier = null;
            foreach (self::CARRIERS as $key => $label) {
                if (str_contains($low, $key)) {
                    $carrier = $label;
                    break;
                }
            }
            $courier = (bool) preg_match('/kurier|courier/u', $low);
            if ($carrier !== null) {
                return $courier ? 'Kurier ' . $carrier : $carrier;
            }
            if ($courier) {
                return 'Kurier';
            }
            $clean = str_replace(['_', '-'], ' ', $clean);
        }

        return mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
    }

    /** Usuwa „(ręcznie)" i każdą kwotę (np. „(14.75 PLN)", „- 12,99 zł"). */
    public static function stripInternal(string $name): string
    {
        $s = preg_replace('/\s*\(ręcznie\)/u', '', $name) ?? $name;
        // Kwota w nawiasie: „(14.75 PLN)", „(12,99 zł)", „(PLN 9.99)".
        $s = preg_replace('/\s*[\(\[][^\)\]]*\d[^\)\]]*(?:pln|zł|zl|eur|€)[^\)\]]*[\)\]]/iu', '', $s) ?? $s;
        $s = preg_replace('/\s*[\(\[]\s*(?:pln|zł|zl|eur|€)[^\)\]]*\d[^\)\]]*[\)\]]/iu', '', $s) ?? $s;
        // Kwota bez nawiasu, na końcu: „- 14,75 zł", ": 9.99 PLN", „14.75PLN".
        $s = preg_replace('/\s*[-–—:,]?\s*\d+(?:[.,]\d+)?\s*(?:pln|zł|zl|eur|€)\.?\s*$/iu', '', $s) ?? $s;
        return trim($s, " \t\n\r\0\x0B-–—:,");
    }
}
