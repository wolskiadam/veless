<?php
declare(strict_types=1);

namespace PaseExt\Meta;

/** Błąd Graph API z komunikatem po polsku. $status: kod HTTP (0 = brak połączenia), $code: kod błędu Meta. */
final class MetaApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly int $metaCode = 0)
    {
        parent::__construct($message);
    }
}
