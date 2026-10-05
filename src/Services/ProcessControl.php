<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Wstrzymywanie pracy w tle (System → Obciążenie serwera), gdy serwer nie daje rady.
 *
 * Każdy proces ma w settings klucz PAUSE_<NAZWA> = znacznik czasu, do kiedy jest wstrzymany
 * ('' / brak = działa). Wstrzymanie „do odwołania” to odległa data. Po upływie czasu proces
 * sam wraca do pracy - nic nie zostaje przypadkiem wyłączone na zawsze.
 *
 * Czego NIE da się tu wstrzymać (celowo):
 *  - odświeżania tokenów Allegro/sklepów - bez tego po wznowieniu trzeba by łączyć konta od nowa,
 *  - webhooków ze sklepów i Allegro - zamówienie trafia wtedy do kolejki i zostanie
 *    przetworzone po wznowieniu (nic nie ginie),
 *  - pracy w panelu i strony klienta.
 */
final class ProcessControl
{
    /** Do odwołania = 2100-01-01. */
    public const INDEFINITE = 4102444800;

    /** Procesy do wstrzymania: klucz => [nazwa, opis]. */
    public const PROCESSES = [
        'worker'   => ['Cała praca w tle', 'Cron nie robi nic poza znakiem życia: bez kolejki, synchronizacji i archiwizacji. Tokeny też czekają - wznowienie w ciągu kilku godzin jest bezpieczne.'],
        'queue'    => ['Przetwarzanie kolejki zadań', 'Import zamówień i produktów, wypychanie stanów. Nowe zadania czekają w kolejce i wykonają się po wznowieniu.'],
        'orders'   => ['Pobieranie zamówień', 'Cykliczne sprawdzanie nowych zamówień w sklepach i na Allegro. Zamówienia z webhooków nadal trafiają do kolejki.'],
        'stock'    => ['Synchronizacja stanów', 'Wysyłanie stanów magazynowych do sklepów i na Allegro.'],
        'price'    => ['Synchronizacja cen', 'Wysyłanie cen do sklepów i na Allegro.'],
        'products' => ['Import produktów', 'Cykliczne pobieranie produktów ze sklepów.'],
        'tracking' => ['Śledzenie przesyłek', 'Odpytywanie przewoźników o status paczek.'],
        'handling' => ['Harmonogram czasu wysyłki Allegro', 'Codzienne zmiany czasu wysyłki w ofertach.'],
        'allegro_dashboard' => ['Dashboard Allegro', 'Cogodzinne pobieranie jakości sprzedaży i finansów z Allegro.'],
        'allegro_returns' => ['Zwroty z Allegro', 'Pobieranie zwrotów zgłoszonych przez kupujących (co 30 minut). Nie zwraca pieniędzy ani nie wystawia korekt.'],
        'allegro_feedback' => ['Dyskusje i oceny Allegro', 'Pobieranie dyskusji, reklamacji i ocen sprzedaży (co 30 minut). Nic nie wysyła do kupujących.'],
        'tiktokshop' => ['TikTok Shop', 'Pobieranie zamówień z TikTok Shop, wysyłanie stanów i numerów przesyłek (co 5 minut).'],
        'archive'  => ['Auto-archiwizacja zamówień', 'Przenoszenie starych zamówień do archiwum.'],
        'print'    => ['Agent drukarki (wydruki)', 'Serwer nie wydaje wydruków - czekają i wydrukują się po wznowieniu. Agent pyta wtedy rzadko.'],
        'cleanup'  => ['Czyszczenie kolejki', 'Usuwanie starych wykonanych zadań z kolejki (zadania z błędem zostają).'],
    ];

    /** Dozwolone limity zadań kolejki na jeden przebieg crona (co minutę). */
    public const BATCH_OPTIONS = [5, 10, 25, 50, 100];
    public const BATCH_SETTING = 'WORKER_BATCH_LIMIT';

    public function __construct(private readonly SettingsRepository $settings) {}

    public static function settingKey(string $process): string
    {
        if (!isset(self::PROCESSES[$process])) { throw new \InvalidArgumentException('Nieznany proces: ' . $process); }
        return 'PAUSE_' . strtoupper($process);
    }

    /** Do kiedy wstrzymany (timestamp) albo null, gdy działa. */
    public function pausedUntil(string $process, ?int $now = null): ?int
    {
        $until = (int) ($this->settings->get(self::settingKey($process), '0') ?? '0');
        return $until > ($now ?? time()) ? $until : null;
    }

    public function isPaused(string $process, ?int $now = null): bool
    {
        return $this->pausedUntil($process, $now) !== null;
    }

    /** @param int|null $seconds null = do odwołania */
    public function pause(string $process, ?int $seconds): int
    {
        $until = $seconds === null ? self::INDEFINITE : time() + max(60, $seconds);
        $this->settings->setMany([self::settingKey($process) => (string) $until]);
        return $until;
    }

    public function resume(string $process): void
    {
        $this->settings->setMany([self::settingKey($process) => '']);
    }

    /** @return array<string,int> aktualnie wstrzymane: proces => do kiedy */
    public function activePauses(?int $now = null): array
    {
        $out = [];
        foreach (array_keys(self::PROCESSES) as $p) {
            $until = $this->pausedUntil($p, $now);
            if ($until !== null) { $out[$p] = $until; }
        }
        return $out;
    }

    /** Ile zadań kolejki na przebieg: ustawienie z panelu albo domyślne z konfiguracji. */
    public function batchSize(int $default): int
    {
        $v = (int) ($this->settings->get(self::BATCH_SETTING, '0') ?? '0');
        return in_array($v, self::BATCH_OPTIONS, true) ? $v : $default;
    }

    public function setBatchSize(?int $size): void
    {
        $this->settings->setMany([self::BATCH_SETTING => $size !== null && in_array($size, self::BATCH_OPTIONS, true) ? (string) $size : '']);
    }
}
