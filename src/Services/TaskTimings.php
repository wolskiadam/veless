<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Czasy zadań w tle, które nie są „przepływami” synchronizacji (te mają SYNC_*_EVERY),
 * a dotąd były wpisane na stałe w kodzie. Ustawiane w Konfiguracja → Synchronizacja,
 * sekcja „Pozostałe zadania w tle”. Każda wartość ma bezpieczny zakres - poza nim
 * używany jest najbliższy dozwolony (np. tokeny nie rzadziej niż co 10 min, bo odnawiamy
 * je 15 min przed wygaśnięciem - rzadsze sprawdzanie mogłoby je przegapić).
 */
final class TaskTimings
{
    /**
     * klucz => [etykieta, opis, jednostka, domyślnie, min, max, czy 0 = wyłączone, znacznik ostatniego uruchomienia]
     * @var array<string,array{0:string,1:string,2:string,3:int,4:int,5:int,6:bool,7:?string}>
     */
    public const DEFS = [
        'ARCHIVE_EVERY' => ['Auto-archiwizacja zamówień', 'Co ile minut przenosić stare zamówienia do archiwum. 0 = wyłączona.', 'min', 60, 5, 10080, true, 'ORDERS_AUTOARCHIVE_AT'],
        'ARCHIVE_AFTER_DAYS' => ['Archiwizuj zamówienia starsze niż', 'Wiek zamówienia (od daty złożenia), po którym trafia do archiwum.', 'dni', 90, 7, 3650, false, null],
        'TOKENS_EVERY' => ['Odświeżanie tokenów Allegro i sklepów', 'Co ile minut sprawdzać, czy token wygasa (odnawiany 15 min przed końcem). Nie da się wyłączyć - bez tokenów integracje przestają działać.', 'min', 5, 1, 10, false, 'TOKENS_REFRESH_AT'],
        'TRACKING_MAX_AGE_DAYS' => ['Śledź przesyłki nadane w ostatnich', 'Starszych paczek nie odpytujemy już u przewoźnika - mniej zapytań do API.', 'dni', 45, 1, 365, false, null],
        'QUEUE_KEEP_DONE_DAYS' => ['Usuwaj wykonane zadania kolejki starsze niż', 'Zadania z błędem zostają, dopóki ich nie ponowisz lub nie usuniesz. 0 = nie usuwaj.', 'dni', 14, 1, 365, true, null],
        'QUEUE_CLEANUP_EVERY' => ['Czyszczenie kolejki', 'Co ile minut usuwać stare wykonane zadania.', 'min', 60, 5, 10080, false, 'QUEUE_CLEANUP_AT'],
        'PRINT_POLL_ACTIVE' => ['Agent drukarki: pytaj o wydruki w trakcie pracy co', 'Gdy ktoś pracuje w panelu lub niedawno drukowano. Mniej = szybszy wydruk, więcej zapytań.', 's', 4, 2, 60, false, null],
        'PRINT_POLL_IDLE' => ['Agent drukarki: pytaj o wydruki bez pracy co', 'Gdy nikt nie pracuje - pierwszy wydruk po przerwie może poczekać do tylu sekund.', 's', 30, 5, 300, false, null],
        'PRINT_IDLE_AFTER' => ['Agent drukarki: uznaj, że nikt nie pracuje, po', 'Tyle minut bez aktywności w panelu i bez wydruków przełącza agenta na rzadkie pytanie.', 'min', 10, 1, 240, false, null],
        'QUEUE_STUCK_MINUTES' => ['Ponów zawieszone zadanie kolejki po', 'Zadanie, które utknęło (np. przerwany przebieg crona), wraca do kolejki po tym czasie.', 'min', 10, 2, 240, false, null],
    ];

    /** Wartość z ustawień, przycięta do dozwolonego zakresu; brak ustawienia = domyślna. */
    public static function get(SettingsRepository $settings, string $key): int
    {
        $raw = $settings->get($key);
        return self::sanitize($key, $raw === null ? self::DEFS[$key][3] : $raw);
    }

    public static function sanitize(string $key, mixed $value): int
    {
        if (!isset(self::DEFS[$key])) { throw new \InvalidArgumentException('Nieznany czas: ' . $key); }
        [, , , $default, $min, $max, $zeroOff] = self::DEFS[$key];
        if (!is_numeric($value)) { return $default; }
        $v = (int) $value;
        if ($zeroOff && $v <= 0) { return 0; }
        return max($min, min($max, $v));
    }

    /** Czytelny opis, np. „co 60 min” (interwał) / „90 dni” / „10 min” / „wyłączona”. */
    public static function label(SettingsRepository $settings, string $key): string
    {
        $v = self::get($settings, $key);
        if ($v === 0 && self::DEFS[$key][6]) { return 'wyłączona'; }
        return (str_ends_with($key, '_EVERY') ? 'co ' : '') . $v . ' ' . self::DEFS[$key][2];
    }
}
