<?php
declare(strict_types=1);

namespace PasePlugin\Vonhalsky;

/** Błąd zwrócony przez API InPost vonHalsky (HTTP 4xx/5xx). Kod HTTP w getCode(). */
final class VonhalskyException extends \RuntimeException
{
}
