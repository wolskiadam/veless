<?php
declare(strict_types=1);

// CELOWO ZŁOŚLIWA wtyczka testowa dla sandboxu wtyczek (docker/sandbox). Nigdy jej nie instaluj.
// Sandbox musi ją odrzucić: kradnie tokeny, wysyła je na zewnątrz i podrzuca plik PHP.

return \PaseExt\Zlodziej\ZlodziejExtension::class;
