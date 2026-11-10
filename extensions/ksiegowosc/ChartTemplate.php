<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Wzorcowy plan kont dla małej spółki z o.o. (wariant porównawczy rachunku zysków i strat, produkcja i handel).
 * Konta z kreską (np. 130-01) to analityka: zapisy idą tylko na konta bez kont podrzędnych.
 */
final class ChartTemplate
{
    public const KINDS = ['A' => 'aktywne', 'P' => 'pasywne', 'AP' => 'aktywno-pasywne', 'W' => 'wynikowe', 'PB' => 'pozabilansowe'];

    /** @return list<array{0:string,1:string,2:string}> numer, nazwa, rodzaj */
    public static function spzoo(): array
    {
        return [
            // Zespół 0 - aktywa trwałe
            ['010', 'Środki trwałe', 'A'],
            ['020', 'Wartości niematerialne i prawne', 'A'],
            ['070', 'Umorzenie środków trwałych i WNiP', 'P'],
            ['080', 'Środki trwałe w budowie', 'A'],
            // Zespół 1 - środki pieniężne
            ['100', 'Kasa', 'A'],
            ['130', 'Rachunki bankowe', 'A'],
            ['130-01', 'Rachunek bieżący', 'A'],
            ['130-02', 'Rachunek VAT (split payment)', 'A'],
            ['135', 'Rachunek walutowy', 'A'],
            ['139', 'Środki pieniężne w drodze (PayU, Allegro, Przelewy24)', 'A'],
            // Zespół 2 - rozrachunki
            ['200', 'Rozrachunki z odbiorcami', 'AP'],
            ['202', 'Rozrachunki z dostawcami', 'AP'],
            ['221', 'VAT naliczony', 'A'],
            ['222', 'VAT należny', 'P'],
            ['223', 'Rozliczenie VAT z urzędem skarbowym', 'AP'],
            ['225', 'Rozrachunki z US - podatek dochodowy CIT', 'AP'],
            ['226', 'Rozrachunki z US - PIT pracowników', 'AP'],
            ['229', 'Rozrachunki z ZUS', 'AP'],
            ['230', 'Rozrachunki z tytułu wynagrodzeń', 'AP'],
            ['234', 'Pozostałe rozrachunki z pracownikami', 'AP'],
            ['240', 'Pozostałe rozrachunki', 'AP'],
            ['249', 'Rozrachunki ze wspólnikami', 'AP'],
            ['290', 'Odpisy aktualizujące należności', 'P'],
            // Zespół 3 - materiały i towary
            ['300', 'Rozliczenie zakupu', 'AP'],
            ['310', 'Materiały', 'A'],
            ['330', 'Towary', 'A'],
            // Zespół 4 - koszty według rodzajów
            ['400', 'Amortyzacja', 'W'],
            ['401', 'Zużycie materiałów i energii', 'W'],
            ['402', 'Usługi obce', 'W'],
            ['402-01', 'Usługi kurierskie i pocztowe', 'W'],
            ['402-02', 'Prowizje marketplace i płatności', 'W'],
            ['402-03', 'Pozostałe usługi obce', 'W'],
            ['403', 'Podatki i opłaty', 'W'],
            ['404', 'Wynagrodzenia', 'W'],
            ['405', 'Ubezpieczenia społeczne i inne świadczenia', 'W'],
            ['409', 'Pozostałe koszty rodzajowe', 'W'],
            ['490', 'Rozliczenie kosztów', 'W'],
            // Zespół 6 - produkty i rozliczenia międzyokresowe
            ['600', 'Produkty gotowe', 'A'],
            ['640', 'Rozliczenia międzyokresowe kosztów', 'AP'],
            // Zespół 7 - przychody i koszty ich uzyskania
            ['700', 'Przychody ze sprzedaży produktów', 'W'],
            ['730', 'Przychody ze sprzedaży towarów', 'W'],
            ['731', 'Wartość sprzedanych towarów w cenach zakupu', 'W'],
            ['740', 'Przychody ze sprzedaży materiałów', 'W'],
            ['750', 'Przychody finansowe', 'W'],
            ['751', 'Koszty finansowe', 'W'],
            ['760', 'Pozostałe przychody operacyjne', 'W'],
            ['761', 'Pozostałe koszty operacyjne', 'W'],
            // Zespół 8 - kapitały, fundusze, wynik
            ['800', 'Kapitał zakładowy', 'P'],
            ['810', 'Kapitał zapasowy', 'P'],
            ['820', 'Rozliczenie wyniku finansowego', 'AP'],
            ['840', 'Rezerwy i rozliczenia międzyokresowe przychodów', 'P'],
            ['860', 'Wynik finansowy', 'AP'],
            ['870', 'Podatek dochodowy od osób prawnych', 'W'],
        ];
    }
}
