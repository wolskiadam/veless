<?php
declare(strict_types=1);

namespace Pase\Services;

final class OperationalHealth
{
    /** @return list<string> No customer data or raw exception messages. */
    public static function issues(int $lastWorkerRun, int $failedJobs, int $now): array
    {
        $issues = [];
        if ($lastWorkerRun <= 0 || $lastWorkerRun < $now - 600 || $lastWorkerRun > $now + 60) {
            $issues[] = 'Brak aktualnego sygnału procesu synchronizacji (próg: 10 minut).';
        }
        if ($failedJobs > 0) {
            $issues[] = 'Kolejka zawiera zadania zakończone błędem. Sprawdź widok Kolejka.';
        }
        return $issues;
    }

    public static function shouldNotify(string $current, string $previous, int $lastAttempt, int $now): bool
    {
        // Global ten-minute throttle also limits flapping and SMTP failures.
        if ($lastAttempt > $now - 600) {
            return false;
        }
        return $current !== $previous || ($current !== '' && $lastAttempt <= $now - 21600);
    }
}
