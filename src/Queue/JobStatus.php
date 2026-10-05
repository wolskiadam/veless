<?php
declare(strict_types=1);

namespace Pase\Queue;

/**
 * Statusy zadania w tabeli job_queue. Enum PHP 8.1 - odpowiednik ENUM w MySQL.
 */
enum JobStatus: string
{
    case Pending  = 'pending';   // czeka na workera
    case Reserved = 'reserved';  // zarezerwowane przez workera (lock)
    case Done     = 'done';      // przetworzone z sukcesem
    case Failed   = 'failed';    // wyczerpano max_attempts
}
