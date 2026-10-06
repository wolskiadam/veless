<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Ustawienia firmy (Konfiguracja → Firma).
 *
 * Płatnik VAT (domyślnie tak): przychody i koszty w marży liczone netto, a ceny z zamówień bez podatku
 * dzielone przez stawkę VAT. Firma bez VAT (zwolnienie podmiotowe) nie odlicza podatku, więc wszystko
 * liczy w kwotach, które faktycznie płaci i dostaje: ceny z zamówień, koszt zakupu i opłaty Allegro
 * bez dzielenia przez VAT, a w panelu nie ma podziału na netto i brutto.
 */
final class CompanySettings
{
    public const VAT_PAYER_KEY = 'COMPANY_VAT_PAYER';

    public static function isVatPayer(PDO $pdo): bool
    {
        try {
            return (string) ((new SettingsRepository($pdo))->get(self::VAT_PAYER_KEY, '1') ?? '1') !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Zmiana statusu VAT przelicza przychody w migawkach marży (koszty zakupu zapisane na pozycjach zostają).
     */
    public static function setVatPayer(PDO $pdo, bool $payer): void
    {
        $changed = self::isVatPayer($pdo) !== $payer;
        (new SettingsRepository($pdo))->setMany([self::VAT_PAYER_KEY => $payer ? '1' : '0']);
        if ($changed) {
            (new OrderMargins($pdo))->markAllStale();
        }
    }

    /** Dopisek do kwot w panelu: „ netto” u płatnika VAT, pusty bez VAT. */
    public static function netSuffix(PDO $pdo): string
    {
        return self::isVatPayer($pdo) ? ' netto' : '';
    }
}
