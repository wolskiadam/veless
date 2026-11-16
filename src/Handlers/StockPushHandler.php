<?php
declare(strict_types=1);

namespace Pase\Handlers;

/**
 * Zadanie 'woo.stock.push' = wypych stanu/ceny z CRM do wszystkich miejsc sprzedaży:
 * sklep Woo (WooStockPushHandler) i oferty Allegro (AllegroStockPushHandler).
 *
 * Każdy kanał idzie niezależnie - błąd sklepu nie blokuje Allegro i odwrotnie.
 * Gdy któryś się nie uda, rzucamy wyjątek na końcu i Worker ponawia całe zadanie;
 * oba wypychy ustawiają stan na wartość z CRM, więc powtórka nic nie psuje.
 */
final class StockPushHandler
{
    /** @param object[] $handlers obiekty z metodą handle(array $payload) */
    public function __construct(private readonly array $handlers) {}

    public function handle(array $payload): void
    {
        $errors = [];
        foreach ($this->handlers as $h) {
            try {
                $h->handle($payload);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode(' | ', $errors));
        }
    }
}
