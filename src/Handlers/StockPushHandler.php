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

    /**
     * @return string|null wyniki kanałów połączone „ · ”, np. „SKU WMG60GC · Sklep AWIEN WWW: stan 15 · Allegro: stan 15 -> oferta 123”
     */
    public function handle(array $payload): ?string
    {
        $done = [];
        $errors = [];
        foreach ($this->handlers as $h) {
            try {
                $r = $h->handle($payload);
                if (is_string($r) && $r !== '') {
                    $done[] = $r;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors !== []) {
            // Udane kanały też w treści błędu, żeby w historii było widać, co jednak poszło.
            throw new \RuntimeException(implode(' · ', array_merge($errors, $done)));
        }
        return $done !== [] ? implode(' · ', $done) : null;
    }
}
