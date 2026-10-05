<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

use Pase\Plugin\ExtensionManifest;

/**
 * Wtyczka rozszerzająca: dokłada funkcje do samego CRM (kolumny na liście zamówień,
 * sekcje karty zamówienia, kroki pakowania, akcje automatyzacji, kafelki statystyk,
 * własne strony). Nie łączy się z zewnętrznym API i nie ma kont jak integracje.
 *
 * Żyje w extensions/<slug>/, register.php zwraca FQCN klasy (namespace PaseExt\<Slug>).
 * W boot() podpina się pod haki rdzenia (Pase\Plugin\Hooks) - lista w docs/EXTENSIONS.md.
 */
interface ExtensionPlugin
{
    public function manifest(): ExtensionManifest;

    /** Rejestracja haków. Wywoływane raz na żądanie, przy pierwszym użyciu haka. */
    public function boot(): void;
}
