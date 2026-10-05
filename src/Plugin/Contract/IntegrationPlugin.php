<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

use Pase\Plugin\PluginManifest;

/**
 * Bazowy kontrakt każdej wtyczki integracji. Wtyczka to klasa implementująca ten
 * interfejs; opcjonalnie dokłada interfejsy zdolności (OrderSource, Warehouse,
 * Courier, Invoicing).
 *
 * Cykl życia:
 *  1) Rejestr tworzy instancję wtyczki (konstruktor bezargumentowy).
 *  2) manifest() opisuje typ, pola konfiguracji i zdolności.
 *  3) Dla konkretnego konta wołamy withConfig($config) - zwraca instancję
 *     „związaną" z danymi tego konta (klucze API itp.). Wszystkie operacje
 *     (test, pobieranie, wysyłka) działają na tej związanej instancji.
 */
interface IntegrationPlugin
{
    /** Metadane + deklaracja pól konfiguracji. Bez efektów ubocznych. */
    public function manifest(): PluginManifest;

    /**
     * Zwraca instancję wtyczki związaną z konfiguracją danego konta.
     * @param array<string,mixed> $config odkodowany config integration_accounts
     */
    public function withConfig(array $config): static;

    /**
     * Test połączenia dla bieżącej konfiguracji.
     * @return array{ok:bool,message:string} wynik do pokazania w panelu
     */
    public function testConnection(): array;
}
