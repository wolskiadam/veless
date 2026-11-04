<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

/**
 * Miejsce na kod licencyjny. Docelowo wtyczka będzie wymagała ważnego kodu (pole w ustawieniach + sprawdzenie),
 * na razie działa bez niego. Gdy active() zwróci false, boot() nie rejestruje żadnej strony ani haka.
 */
final class License
{
    public static function active(): bool
    {
        return true;
    }
}
