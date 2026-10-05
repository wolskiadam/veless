<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

/**
 * Stan faktyczny (products.actual_stock) - ile towaru naprawdę jest na półce.
 *
 * Stan w CRM (pase_stock) bywa zawyżony celowo, bo idzie do sklepu i na Allegro. Stan faktyczny jest
 * tylko nasz: nic go nie wysyła do Woo ani Allegro. Pusty (NULL) = jeszcze nie policzony - wtedy nic
 * go nie zmienia. Wpisany schodzi przy każdej sprzedaży i wraca przy przyjęciu zwrotu na stan:
 *  - Allegro: ProductRepository::decrementStockBySku (razem ze stanem w CRM),
 *  - sklep Woo: applyWooOrder() poniżej (Woo samo pilnuje swojego stanu, więc tylko faktyczny),
 *  - zwrot przyjęty na stan: OrderReturns::restock.
 * Zamówienie ze sklepu liczymy tylko, jeśli złożono je po wpisaniu stanu faktycznego
 * (actual_stock_set_at) - starsze są już w tej liczbie (np. import historii zamówień).
 * Każde zamówienie odejmujemy raz (tabela actual_stock_moves); anulowane oddaje to, co odjęło.
 */
final class ActualStock
{
    /** Ustawienie „pokazuj i używaj stanu faktycznego” (Konfiguracja → Synchronizacja). Domyślnie wyłączone. */
    public const SETTING = 'ACTUAL_STOCK_ENABLED';

    /** Statusy Woo, przy których zamówienie nie zabiera towaru. */
    private const WOO_VOID = ['cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Czy pole jest włączone. Wyłączone = ukryte i pomijane w alertach niskiego stanu; zapisane liczby
     * zostają (sprzedaże dalej je zmieniają), więc ponowne włączenie niczego nie gubi.
     */
    public static function enabled(PDO $pdo): bool
    {
        try {
            return (new SettingsRepository($pdo))->get(self::SETTING, '') === '1';
        } catch (\PDOException) {
            return false;
        }
    }

    public static function setEnabled(PDO $pdo, bool $on): void
    {
        (new SettingsRepository($pdo))->setMany([self::SETTING => $on ? '1' : '0']);
    }

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'products', 'actual_stock')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN actual_stock INT NULL');
        }
        if (!LowStock::columnExists($pdo, 'products', 'actual_stock_set_at')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN actual_stock_set_at DATETIME NULL');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS actual_stock_moves (
            ref VARCHAR(80) NOT NULL PRIMARY KEY,
            lines_json TEXT NOT NULL,
            reverted_at DATETIME NULL,
            created_at DATETIME NOT NULL
        )');
    }

    /**
     * Zamówienie ze sklepu Woo (import/aktualizacja): odejmij raz, oddaj przy anulowaniu.
     * @return int liczba zmienionych pozycji
     */
    public function applyWooOrder(int $integrationId, int $wooOrderId, array $payload): int
    {
        $ref = 'woo:' . $integrationId . ':' . $wooOrderId;
        $status = (string) ($payload['status'] ?? '');
        $st = $this->pdo->prepare('SELECT lines_json, reverted_at FROM actual_stock_moves WHERE ref = ?');
        $st->execute([$ref]);
        $move = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (in_array($status, self::WOO_VOID, true)) {
            if ($move === null || $move['reverted_at'] !== null) {
                return 0;
            }
            $lines = json_decode((string) $move['lines_json'], true) ?: [];
            $this->atomic(function () use ($ref, $lines): void {
                $upd = $this->pdo->prepare('UPDATE actual_stock_moves SET reverted_at = ? WHERE ref = ? AND reverted_at IS NULL');
                $upd->execute([gmdate('Y-m-d H:i:s'), $ref]);
                if ($upd->rowCount() !== 1) {
                    return;
                }
                $back = $this->pdo->prepare('UPDATE products SET actual_stock = actual_stock + ? WHERE id = ? AND actual_stock IS NOT NULL');
                foreach ($lines as $l) {
                    $back->execute([(int) $l['qty'], (int) $l['product_id']]);
                }
            });
            Logger::info("Stan faktyczny: zamówienie Woo #{$wooOrderId} ({$status}) - oddano " . count($lines) . ' poz.');
            return count($lines);
        }
        if ($move !== null) {
            return 0;   // już odjęte (albo anulowane i przywrócone - nie odejmujemy drugi raz)
        }

        $raw = (string) ($payload['date_created_gmt'] ?? $payload['date_created'] ?? '');
        $orderedAt = $raw !== '' ? str_replace('T', ' ', substr($raw, 0, 19)) : gmdate('Y-m-d H:i:s');
        $find = $this->pdo->prepare('SELECT id FROM products WHERE sku = ? AND actual_stock IS NOT NULL
                                     AND (actual_stock_set_at IS NULL OR actual_stock_set_at <= ?) LIMIT 1');
        $lines = [];
        foreach ($payload['line_items'] ?? [] as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $qty = (int) ($item['quantity'] ?? 0);
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $find->execute([$sku, $orderedAt]);
            $pid = $find->fetchColumn();
            if ($pid !== false) {
                $lines[] = ['product_id' => (int) $pid, 'qty' => $qty];
            }
        }
        $this->atomic(function () use ($ref, $lines): void {
            // Wpis zawsze (także bez pozycji) - kolejne aktualizacje tego zamówienia nic już nie odejmą.
            $ins = $this->pdo->prepare('INSERT INTO actual_stock_moves (ref, lines_json, created_at) VALUES (?, ?, ?)');
            try {
                $ins->execute([$ref, json_encode($lines), gmdate('Y-m-d H:i:s')]);
            } catch (\PDOException) {
                return;   // równoległy import tego samego zamówienia już odjął
            }
            $sub = $this->pdo->prepare('UPDATE products SET actual_stock = CASE WHEN actual_stock > ? THEN actual_stock - ? ELSE 0 END WHERE id = ? AND actual_stock IS NOT NULL');
            foreach ($lines as $l) {
                $sub->execute([$l['qty'], $l['qty'], $l['product_id']]);
            }
        });
        if ($lines !== []) {
            Logger::info("Stan faktyczny: zamówienie Woo #{$wooOrderId} - odjęto " . count($lines) . ' poz.');
        }
        return count($lines);
    }

    private function atomic(callable $fn): void
    {
        if ($this->pdo->inTransaction()) {
            $fn();
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
