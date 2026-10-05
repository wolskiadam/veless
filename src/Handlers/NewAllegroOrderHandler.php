<?php
declare(strict_types=1);

namespace Pase\Handlers;

use Pase\Automation\RuleEngine;
use Pase\Domain\OrderStatus;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderLogRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;

/**
 * Moduł 2: przetwarzanie nowego zamówienia z Allegro.
 * Wywoływany przez Worker dla zadań typu 'allegro.order.new'.
 *
 * CRM jest głównym miejscem prawdy o zamówieniach i magazynie - zamówienie z Allegro
 * NIE tworzy nowego zamówienia w WooCommerce (dawniej tak było). Zamiast tego:
 *
 * Przepływ:
 *   1. Idempotencja: claim w order_logs po (allegro, source_order_id).
 *   2. Dopasowanie pozycji do magazynu (products). Brak produktu NIE blokuje importu -
 *      taka pozycja wchodzi do zamówienia i na fakturę, tylko bez odjęcia stanu.
 *   3. Zapis zamówienia do woo_orders (wspólna lista "Zamówienia" w panelu, źródło =
 *      Allegro - patrz sourceBadge() w _orders_table.php, które już to wspiera) - BEZ
 *      tworzenia czegokolwiek w realnym sklepie Woo.
 *   4. Odjęcie sprzedanej ilości od stanu PASE (products.pase_stock) + zakolejkowanie
 *      wypchu tego stanu do Woo (woo.stock.push - ten sam mechanizm co ręczna edycja
 *      w Produktach), żeby sklep pokazywał aktualny stan.
 *   5. Aktualizacja statusów w order_logs.
 *
 * Faktury/paragonu handler NIE wystawia - robi to WfirmaDocumentService (przycisk na
 * stronie zamówienia albo automatyzacja na zdarzenie 'Pobrano zamówienie'), tak samo
 * jak dla zamówień z Woo. Dawny krok "wFirma" wołał atrapę WfirmaService, która
 * zapisywała w order_logs zmyślony numer INV-xxxxxx zamiast prawdziwej faktury.
 *
 * Rzucenie wyjątku = sygnał dla Workera, by ponowić (retry+backoff).
 */
final class NewAllegroOrderHandler
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OrderLogRepository $orderLogs,
        private readonly WooOrderRepository $wooOrders,
        private readonly IntegrationAccountRepository $integrations,
        private readonly Queue $queue,
        private readonly ?RuleEngine $ruleEngine = null
    ) {}

    public function handle(array $payload): void
    {
        // Poller/import historyczny wrzucają surowy obiekt checkout-form z Allegro (GET
        // /order/checkout-forms/{id}) - tam id zamówienia jest wprost jako $payload['id'].
        // order_id/checkoutForm.id zostają jako fallback dla starego formatu webhooka.
        $sourceOrderId = (string) ($payload['id'] ?? $payload['order_id'] ?? $payload['checkoutForm']['id'] ?? '');
        if ($sourceOrderId === '') {
            throw new \InvalidArgumentException('Brak source_order_id w payloadzie Allegro');
        }

        // --- 1. IDEMPOTENCJA ---
        [$logId, $isNew] = $this->orderLogs->claim('allegro', $sourceOrderId);
        if (!$isNew) {
            $existing = $this->orderLogs->findBySource('allegro', $sourceOrderId);
            if (($existing['internal_status'] ?? '') === 'completed') {
                Logger::info("Order {$sourceOrderId} już ukończone - pomijam (idempotencja)");
                return;
            }
            Logger::info("Order {$sourceOrderId} wznawiane od stanu: {$existing['internal_status']}");
        }

        try {
            // --- 2. SPRAWDZENIE POZYCJI W MAGAZYNIE ---
            $items = $payload['line_items'] ?? $payload['lineItems'] ?? [];

            // SKU pozycji ustalamy w dwóch krokach:
            //   a) sygnatura oferty (offer.external.id) albo pole sku - gdy równa się SKU w magazynie,
            //   b) powiązanie z zakładki "Powiązania" (product_mappings.allegro_offer_id = offer.id).
            // Bez (b) każda oferta, której sygnatura różni się od SKU magazynowego, blokowała
            // import całego zamówienia, mimo że ręcznie ją powiązano.
            $resolvedSkus = [];
            foreach ($items as $idx => $item) {
                $resolvedSkus[$idx] = $this->resolveSku($item);
            }
            $inWarehouse = $this->products->findManyBySku(array_values(array_filter($resolvedSkus)));

            $orderTotal = 0.0;
            $missing = [];
            foreach ($items as $idx => $item) {
                $sku = $resolvedSkus[$idx];
                $qty = (int) ($item['quantity'] ?? 1);

                if ($sku === null || !isset($inWarehouse[$sku])) {
                    // Produktu nie ma w magazynie CRM - NIE blokujemy zamówienia. Pozycja trafia
                    // do zamówienia, pomijamy tylko odjęcie stanu (krok 4).
                    $missing[] = ($item['sku'] ?? $item['offer']['external']['id'] ?? 'brak sygnatury')
                        . ' (oferta ' . ($item['offer']['id'] ?? '?') . ')';
                    $resolvedSkus[$idx] = null;
                }

                $unitPrice = (float) ($item['price']['amount'] ?? 0);
                $orderTotal += $unitPrice * $qty;
            }

            // --- 3. ZAPIS DO woo_orders (wspólna lista "Zamówienia", źródło = Allegro) ---
            $allegroAccountId = null;
            foreach ($this->integrations->activeByType('allegro') as $acc) {
                $allegroAccountId = (int) $acc['id'];
                break;
            }

            // Synthetic ID zamiast prawdziwego woo_order_id (nie ma go - to nie zamówienie
            // Woo). Duży, stały offset + hash source_order_id trzyma się z dala od realnych,
            // sekwencyjnych ID zamówień Woo z tego samego sklepu i jest deterministyczny
            // (ten sam SKU/order zawsze mapuje się na to samo ID - bezpieczne dla upsertu).
            $syntheticOrderId = 9_000_000_000 + crc32($sourceOrderId);

            $buyer = $payload['buyer'] ?? [];
            $customerName = trim(($buyer['firstName'] ?? '') . ' ' . ($buyer['lastName'] ?? ''));
            $rawDate = $payload['boughtAt'] ?? $payload['updatedAt'] ?? null;
            $dateCreated = is_string($rawDate) && $rawDate !== ''
                ? str_replace('T', ' ', substr($rawDate, 0, 19))
                : null;
            $fulfillmentStatus = $payload['fulfillment']['status'] ?? null;

            // Pozycje, suma, dostawa i płatność w formacie Woo - bez tego strona zamówienia,
            // faktura i wydruki pokazywały "Brak pozycji" i 0.00 PLN.
            $matched = [];
            foreach ($items as $idx => $item) {
                $sku = $resolvedSkus[$idx];
                $matched[$idx] = [
                    'sku'        => $sku ?? ($item['offer']['external']['id'] ?? null),
                    'product_id' => $sku !== null ? (int) ($inWarehouse[$sku]['id'] ?? 0) : 0,
                ];
            }
            $payload = \Pase\Support\AllegroOrderMapper::withWooFields($payload, $matched);

            $wasPaid = $this->ruleEngine !== null ? \Pase\Automation\OrderEvents::isPaidNow($this->wooOrders->pdo(), $syntheticOrderId) : null;
            $this->wooOrders->upsert($syntheticOrderId, [
                'integration_id' => $allegroAccountId,
                'order_number'   => $sourceOrderId,
                'status'         => $fulfillmentStatus,
                'pase_status'    => OrderStatus::fromAllegro($fulfillmentStatus),
                'currency'       => $payload['payment']['currency'] ?? 'PLN',
                'total'          => (float) $payload['total'],
                'customer_name'  => $customerName !== '' ? $customerName : null,
                'customer_email' => $buyer['email'] ?? null,
                'date_created'   => $dateCreated,
            ], $payload);
            $this->wooOrders->assignNumberIfMissing($syntheticOrderId);
            // Same produkty wirtualne -> „Zamówienie wirtualne — bez wysyłki”.
            (new \Pase\Services\VirtualProducts($this->wooOrders->pdo()))->applyToOrder($syntheticOrderId, (array) ($payload['line_items'] ?? []));
            // Zapamiętaj, pod jakim ID zamówienie siedzi w CRM - bez tego podgląd
            // "Synchronizacja Allegro" pokazywał "zadanie gotowe, ale brak zamówienia".
            $this->orderLogs->setWooOrderId($logId, $syntheticOrderId);

            // Automatyzacje - zdarzenie 'Pobrano zamówienie', raz na zamówienie (tak samo
            // jak przy imporcie z Woo). Wcześniej zamówienia z Allegro w ogóle go nie wywoływały.
            // Błąd automatyzacji nie może wstrzymać importu zamówienia.
            if ($this->ruleEngine !== null && !$this->wooOrders->automationsDone($syntheticOrderId)) {
                try {
                    $this->ruleEngine->dispatch(
                        'order.imported',
                        $syntheticOrderId,
                        $payload
                    );
                    $this->wooOrders->markAutomationsDone($syntheticOrderId);
                } catch (\Throwable $e) {
                    Logger::error("Order {$sourceOrderId}: automatyzacje nie zadziałały", ['error' => $e->getMessage()]);
                }
            }
            if ($this->ruleEngine !== null) {
                \Pase\Automation\OrderEvents::paidTransition($this->wooOrders->pdo(), $syntheticOrderId, $wasPaid, $this->ruleEngine);
            }

            // --- 4. MAGAZYN: odejmij stan + wypchnij do Woo ---
            foreach ($items as $idx => $item) {
                $sku = $resolvedSkus[$idx];
                if ($sku === null) {
                    continue; // poza magazynem - nie ma czego odejmować
                }
                $qty = (int) ($item['quantity'] ?? 1);
                $product = $this->products->decrementStockBySku($sku, $qty);
                if ($product !== null) {
                    $this->queue->enqueue(
                        'woo.stock.push',
                        ['product_id' => (int) $product['id']],
                        'woo.stock.push:' . $product['id'] . ':allegro:' . $sourceOrderId
                    );
                }
            }

            // --- 5. DONE ---
            $this->orderLogs->markCompleted($logId);
            if ($missing !== []) {
                Logger::warn(
                    "Order {$sourceOrderId}: pozycje spoza magazynu CRM - stan nie został odjęty",
                    ['pozycje' => $missing]
                );
            }
            Logger::info("Order {$sourceOrderId} ukończony");
        } catch (\Throwable $e) {
            $this->orderLogs->markFailed($logId, $e->getMessage());
            throw $e; // przekazujemy wyżej -> Worker zdecyduje o retry
        }
    }

    /** SKU magazynowe dla pozycji zamówienia Allegro (null = nie da się ustalić). */
    private function resolveSku(array $item): ?string
    {
        $direct = $item['sku'] ?? $item['offer']['external']['id'] ?? null;
        $direct = is_string($direct) && trim($direct) !== '' ? trim($direct) : null;

        if ($direct !== null && $this->products->findBySku($direct) !== null) {
            return $direct;
        }

        $offerId = (string) ($item['offer']['id'] ?? '');
        if ($offerId !== '') {
            $mapped = $this->products->findSkuByAllegroOfferId($offerId);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $direct;
    }
}
