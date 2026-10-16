<?php
declare(strict_types=1);

namespace PasePlugin\Erli;

/** Błąd zwrócony przez ERLI Shop API (HTTP 4xx/5xx). Kod HTTP w getCode(). */
final class ErliException extends \RuntimeException
{
}
