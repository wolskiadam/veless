<?php
declare(strict_types=1);

namespace Pase\Handlers;

use PDO;
use Pase\Support\Logger;

/**
 * Wypych stanu CRM -> oferty Allegro tego produktu.
 * Uruchamiany razem z wypychem do sklepu (zadanie 'woo.stock.push', patrz StockPushHandler).
 *
 * Wcześniej stan trafiał na Allegro tylko okrężną drogą: CRM -> sklep Woo -> webhook
 * product.updated (webhook_woo.php) -> 'woo.stock.sync' -> Allegro. Ten webhook nie jest
 * nigdzie podpowiadany w panelu, więc u większości instalacji nie istnieje i zmiana stanu
 * w CRM nigdy nie docierała do Allegro (np. SKU WMG60GC: 10 w CRM, 5 na Allegro).
 *
 * Oferty produktu bierzemy z trzech miejsc (jak podgląd ofert na karcie produktu):
 *   1. product_mappings.allegro_offer_id (strona Powiązania),
 *   2. oferta wystawiona z CRM (offer_templates.data.offer_id),
 *   3. aktywne oferty z sygnaturą (external.id) równą SKU - tak samo dopasowuje
 *      zamówienia NewAllegroOrderHandler, więc te oferty i tak zdejmują stan w CRM.
 *
 * Payload: ['product_id' => int, 'what' => 'stock'|'price'|'both', 'allegro' => bool].
 */
final class AllegroStockPushHandler
{
    /** @var (\Closure(): object)|null */
    private ?\Closure $clientFactory;
    private ?object $client = null;

    /**
     * @param \Closure(): object $clientFactory klient Allegro (updateOfferQuantity, listOffersRaw, bearerToken),
     *                                          tworzony dopiero, gdy jest co wysłać
     */
    public function __construct(private readonly PDO $pdo, \Closure $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }

    public function handle(array $payload): void
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        $what = $payload['what'] ?? 'stock';
        if ($productId <= 0 || $what === 'price' || ($payload['allegro'] ?? true) === false) {
            return;
        }

        $st = $this->pdo->prepare('SELECT id, sku, pase_stock FROM products WHERE id = ?');
        $st->execute([$productId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || $p['pase_stock'] === null) {
            return;
        }
        $sku = trim((string) ($p['sku'] ?? ''));
        $stock = max(0, (int) $p['pase_stock']);

        $client = $this->client();
        if ($client === null || $client->bearerToken() === null) {
            return; // Allegro niepołączone - nie ma dokąd wysyłać
        }

        $offerIds = $this->linkedOfferIds($productId, $sku);
        if ($sku !== '') {
            foreach ($client->listOffersRaw('external.id=' . rawurlencode($sku) . '&limit=20') as $o) {
                $status = strtoupper((string) ($o['publication']['status'] ?? ''));
                $id = (string) ($o['id'] ?? '');
                if ($id !== '' && in_array($status, ['ACTIVE', 'ACTIVATING'], true)
                    && (string) ($o['external']['id'] ?? '') === $sku) {
                    $offerIds[$id] = true;
                }
            }
        }
        if ($offerIds === []) {
            return;
        }

        $failed = [];
        foreach (array_keys($offerIds) as $offerId) {
            if ($client->updateOfferQuantity((string) $offerId, $stock)) {
                Logger::info("AllegroStockPush: SKU {$sku} -> oferta {$offerId} = {$stock} szt.");
            } else {
                $failed[] = (string) $offerId;
            }
        }
        if ($failed !== []) {
            // Błąd API -> wyjątek, Worker ponowi z backoffem (PATCH stanu jest idempotentny).
            throw new \RuntimeException('Allegro: nie udało się ustawić stanu ' . $stock . ' dla SKU ' . $sku
                . ' (oferty: ' . implode(', ', $failed) . ')');
        }
    }

    /** @return array<string,true> offer_id => true */
    private function linkedOfferIds(int $productId, string $sku): array
    {
        $ids = [];
        if ($sku !== '') {
            try {
                $st = $this->pdo->prepare('SELECT allegro_offer_id FROM product_mappings WHERE sku = ? AND allegro_offer_id IS NOT NULL');
                $st->execute([$sku]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if (trim((string) $id) !== '') {
                        $ids[trim((string) $id)] = true;
                    }
                }
            } catch (\PDOException) { /* brak tabeli */ }
        }
        try {
            $st = $this->pdo->prepare("SELECT data FROM offer_templates WHERE product_id = ? AND marketplace = 'allegro'");
            $st->execute([$productId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $id = trim((string) ((json_decode((string) $json, true) ?: [])['offer_id'] ?? ''));
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        } catch (\PDOException) { /* brak tabeli */ }
        return $ids;
    }

    private function client(): ?object
    {
        if ($this->client === null && $this->clientFactory !== null) {
            $factory = $this->clientFactory;
            $this->clientFactory = null;
            try {
                $this->client = $factory();
            } catch (\Throwable $e) {
                Logger::warn('AllegroStockPush: brak klienta Allegro', ['error' => $e->getMessage()]);
            }
        }
        return $this->client;
    }
}
