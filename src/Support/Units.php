<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Jednostki wagi i wymiarów. W magazynie CRM trzymamy zawsze kilogramy i centymetry;
 * sklepy (np. WooCommerce) podają wartości w jednostce ustawionej w sklepie.
 */
final class Units
{
    private const WEIGHT_TO_KG = ['kg' => 1.0, 'g' => 0.001, 'lbs' => 0.45359237, 'lb' => 0.45359237, 'oz' => 0.028349523125];
    private const DIM_TO_CM    = ['cm' => 1.0, 'mm' => 0.1, 'm' => 100.0, 'in' => 2.54, 'yd' => 91.44];

    public const WEIGHT_UNITS = ['kg' => 'kilogramy (kg)', 'g' => 'gramy (g)', 'lbs' => 'funty (lbs)', 'oz' => 'uncje (oz)'];
    public const DIM_UNITS    = ['cm' => 'centymetry (cm)', 'mm' => 'milimetry (mm)', 'm' => 'metry (m)', 'in' => 'cale (in)'];

    public static function toKg(?float $value, ?string $unit): ?float
    {
        if ($value === null) {
            return null;
        }
        return round($value * (self::WEIGHT_TO_KG[strtolower((string) $unit)] ?? 1.0), 3);
    }

    public static function toCm(?float $value, ?string $unit): ?float
    {
        if ($value === null) {
            return null;
        }
        return round($value * (self::DIM_TO_CM[strtolower((string) $unit)] ?? 1.0), 2);
    }

    /**
     * Waga niemożliwa przy tych wymiarach (gęstość ponad 15 kg na litr, cięższe niż ołów):
     * prawie zawsze gramy zapisane jako kilogramy (np. 240 „kg” świecy 6 × 6 × 6 cm).
     * Bez kompletu wymiarów nie oceniamy.
     */
    public static function implausibleWeight(mixed $kg, mixed $l, mixed $w, mixed $h): bool
    {
        foreach ([$kg, $l, $w, $h] as $v) {
            if ($v === null || $v === '' || (float) $v <= 0) {
                return false;
            }
        }
        $litres = (float) $l * (float) $w * (float) $h / 1000;
        return (float) $kg > 1 && (float) $kg / $litres > 15;
    }

    /** 0.24 -> „240 g”, 1.25 -> „1,25 kg”, 2 -> „2 kg”. */
    public static function formatWeight(mixed $kg): string
    {
        if ($kg === null || $kg === '') {
            return '—';
        }
        $kg = (float) $kg;
        if ($kg > 0 && $kg < 1) {
            return self::num($kg * 1000, 0) . ' g';
        }
        return self::num($kg, 3) . ' kg';
    }

    /** 6.150 -> „6,15”, 10.00 -> „10”. */
    public static function num(mixed $v, int $maxDecimals = 2): string
    {
        if ($v === null || $v === '') {
            return '?';
        }
        $s = number_format((float) $v, $maxDecimals, ',', ' ');
        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}
