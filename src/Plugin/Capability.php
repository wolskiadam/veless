<?php
declare(strict_types=1);

namespace Pase\Plugin;

/**
 * Katalog zdolności (capabilities), które wtyczka może deklarować w manifeście.
 * Każdej odpowiada interfejs w Pase\Plugin\Contract\*.
 */
final class Capability
{
    public const ORDER_SOURCE = 'order_source'; // pobieranie zamówień (Woo, Allegro)
    public const WAREHOUSE    = 'warehouse';     // produkty / stany / ceny (Woo, Allegro)
    public const COURIER      = 'courier';       // przesyłki, etykiety (BLPaczka)
    public const INVOICING    = 'invoicing';     // faktury / dokumenty (wFirma)
    public const SMS          = 'sms';           // wysyłka SMS (SMSAPI)

    /** @return array<string,string> klucz => etykieta (do UI). */
    public static function labels(): array
    {
        return [
            self::ORDER_SOURCE => 'Źródło zamówień',
            self::WAREHOUSE    => 'Magazyn (produkty/stany)',
            self::COURIER      => 'Kurier / wysyłka',
            self::INVOICING    => 'Faktury / księgowość',
            self::SMS          => 'SMS',
        ];
    }

    public static function label(string $cap): string
    {
        return self::labels()[$cap] ?? $cap;
    }

    // ===== Kategorie integracji (do galerii dodawania - jak w BaseLinkerze) =====

    public const CAT_MARKETPLACE = 'marketplace';
    public const CAT_SHOP        = 'shop';
    public const CAT_COURIER     = 'courier';
    public const CAT_ACCOUNTING  = 'accounting';
    public const CAT_OTHER       = 'other';

    /** @return array<string,string> klucz kategorii => etykieta (kolejność = kolejność w galerii) */
    public static function categoryLabels(): array
    {
        return [
            self::CAT_MARKETPLACE => 'Marketplace',
            self::CAT_SHOP        => 'Sklepy',
            self::CAT_COURIER     => 'Kurierzy',
            self::CAT_ACCOUNTING  => 'Księgowość',
            self::CAT_OTHER       => 'Inne',
        ];
    }

    /**
     * Kategoria wyprowadzona ze zdolności wtyczki (gdy manifest nie podał jej wprost).
     * Marketplace vs Sklep nie da się rozróżnić po zdolnościach - domyślnie 'shop'
     * dla source+warehouse; wtyczka marketplace powinna podać category w manifeście.
     * @param string[] $capabilities
     */
    public static function categoryFromCapabilities(array $capabilities): string
    {
        if (in_array(self::COURIER, $capabilities, true)) {
            return self::CAT_COURIER;
        }
        if (in_array(self::INVOICING, $capabilities, true)) {
            return self::CAT_ACCOUNTING;
        }
        if (in_array(self::ORDER_SOURCE, $capabilities, true) || in_array(self::WAREHOUSE, $capabilities, true)) {
            return self::CAT_SHOP;
        }
        return self::CAT_OTHER;
    }
}
