<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Opcjonalna zdolność: wtyczka dostarcza SZABLONY (np. opisu oferty marketplace),
 * które mają pojawić się w zbiorczym hubie „Szablony" (Konfiguracja → Szablony).
 *
 * Dzięki temu hub jest rozszerzalny: nowa wtyczka deklarująca ten interfejs sama
 * pojawia się w hubie, bez zmian w rdzeniu.
 */
interface ProvidesTemplates
{
    /**
     * Lista pozycji szablonów do pokazania w hubie.
     * Każda pozycja: [
     *   'name' => 'Opis ofert Allegro',
     *   'url'  => 'allegro_templates.php',   // strona zarządzania szablonami
     *   'desc' => 'Szablony opisu (moduły Tekst/Grafika) per kategoria',
     *   'icon' => '🧩',                        // opcjonalnie
     *   'count'=> 3,                           // opcjonalnie: liczba szablonów
     * ]
     *
     * @return array<int,array<string,mixed>>
     */
    public function templates(): array;
}
