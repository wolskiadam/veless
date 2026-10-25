<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Czas wysyłki z oferty (delivery.handlingTime) w chwili zakupu - tak jak „Czas wysyłki” przy zamówieniu w panelu
 * Allegro. Checkout-form go nie zawiera, więc przy pobraniu nowego zamówienia odczytujemy go z ofert
 * (GET /sale/product-offers/{id}) i zapisujemy osobno: payload zamówienia bywa później nadpisywany, a czas
 * wysyłki w ofercie się zmienia (np. harmonogram AllegroHandlingSchedule).
 *
 * Zapisujemy tylko dla świeżych zamówień - przy imporcie historycznym oferta ma już dzisiejszy czas wysyłki,
 * nie ten z dnia zakupu, a Allegro nie udostępnia czasu z dnia zakupu w API. Takie zamówienia nie pokazują
 * czasu wysyłki wcale - obecny czas z oferty wprowadzał w błąd.
 */
final class AllegroOrderHandling
{
    /** Ile godzin po zakupie czas z oferty uznajemy za ten, który widział kupujący. */
    public const FRESH_HOURS = 48;

    public static function migrate(PDO $pdo): void
    {
        $tail = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_order_handling (
            woo_order_id BIGINT NOT NULL,
            offer_id VARCHAR(32) NOT NULL,
            handling_time VARCHAR(16) NULL,
            recorded_at DATETIME NOT NULL,
            PRIMARY KEY (woo_order_id, offer_id)
        )$tail");
    }

    /**
     * Zapisuje czas wysyłki ofert z zamówienia (raz - kolejne wywołania nic nie zmieniają).
     * @param callable(string):?array $fetchOffer id oferty => odpowiedź GET /sale/product-offers/{id} albo null
     */
    public static function record(PDO $pdo, int $wooOrderId, array $payload, callable $fetchOffer, ?int $now = null): void
    {
        $bought = strtotime((string) ($payload['boughtAt'] ?? ''));
        if ($bought === false || ($now ?? time()) - $bought > self::FRESH_HOURS * 3600) {
            return;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM allegro_order_handling WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        if ((int) $st->fetchColumn() > 0) {
            return;
        }
        $ignore = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $ins = $pdo->prepare("$ignore INTO allegro_order_handling (woo_order_id, offer_id, handling_time, recorded_at) VALUES (?, ?, ?, ?)");
        foreach (self::offerIds($payload) as $offerId) {
            $offer = $fetchOffer($offerId);
            if (!is_array($offer)) {
                continue;
            }
            $h = (string) ($offer['delivery']['handlingTime'] ?? '');
            $ins->execute([$wooOrderId, $offerId, $h !== '' ? $h : null, gmdate('Y-m-d H:i:s', $now ?? time())]);
        }
    }

    /**
     * Czas wysyłki dla pozycji zamówienia zapisany przy pobraniu (bez zapisu - pusto).
     * @return array<int,array{name:string,handling:string}>
     */
    public static function forOrder(PDO $pdo, int $wooOrderId, array $payload): array
    {
        $ids = self::offerIds($payload);
        if ($ids === []) {
            return [];
        }
        $saved = [];
        try {
            $st = $pdo->prepare('SELECT offer_id, handling_time FROM allegro_order_handling WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $saved[(string) $r['offer_id']] = $r['handling_time'];
            }
        } catch (\Throwable $e) {
            return [];   // brak tabel (przed migracją)
        }
        $names = [];
        foreach ((array) ($payload['lineItems'] ?? []) as $li) {
            $id = (string) ($li['offer']['id'] ?? '');
            if ($id !== '' && !isset($names[$id])) {
                $names[$id] = (string) ($li['offer']['name'] ?? $id);
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $label = AllegroOfferOperations::handlingLabel($saved[$id] ?? null);
            if ($label !== '') {
                $out[] = ['name' => $names[$id] ?? $id, 'handling' => $label];
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function offerIds(array $payload): array
    {
        $ids = [];
        foreach ((array) ($payload['lineItems'] ?? []) as $li) {
            $id = (string) ($li['offer']['id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_map('strval', array_keys($ids));   // numeryczne id ofert PHP zamienia w kluczach na int
    }
}
