<?php
declare(strict_types=1);

namespace Pase\Handlers;

use Pase\Automation\RuleEngine;
use Pase\Domain\OrderStatus;
use Pase\Repository\WooOrderRepository;
use Pase\Services\ActualStock;
use Pase\Services\PaymentFailure;
use Pase\Support\Logger;

/**
 * Import zamówienia z WooCommerce do tabeli woo_orders.
 * Wywoływany przez Worker dla zadań typu 'woo.order.import'.
 *
 * To pełna implementacja (nie stub) - dane zamówienia przychodzą w payloadzie
 * webhooka, więc nie potrzebujemy wywołań do API. Zapis jest idempotentny:
 * powtórka tego samego zamówienia aktualizuje istniejący wiersz.
 *
 * Po zapisie uruchamiany jest silnik automatyzacji (jeśli przekazany).
 */
final class WooOrderImportHandler
{
    public function __construct(
        private readonly WooOrderRepository $repo,
        private readonly ?RuleEngine $ruleEngine = null,
        private readonly ?ActualStock $actualStock = null,
        private readonly ?PaymentFailure $paymentFailure = null
    ) {}

    public function handle(array $payload): void
    {
        $wooOrderId = (int) ($payload['id'] ?? 0);
        if ($wooOrderId <= 0) {
            // Brak ID = nie ma czego zapisać; nie ma sensu retry.
            Logger::warn('WooOrderImport: payload bez id zamówienia - pomijam');
            return;
        }

        // Zamówienie utworzone w sklepie przez automatyzację „Przekaż zamówienie" już jest w CRM
        // (jako zamówienie źródłowe) - nie importujemy go drugi raz.
        foreach ($payload['meta_data'] ?? [] as $meta) {
            if (($meta['key'] ?? '') === 'pase_source_order') {
                Logger::info("WooOrderImport: zamówienie {$wooOrderId} przekazane z CRM (#" . ($meta['value'] ?? '?') . ') - pomijam');
                return;
            }
        }

        $billing = $payload['billing'] ?? [];
        $customerName = trim(
            (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''))
        );

        // Woo podaje datę jako ISO 8601 (np. 2026-06-19T12:00:00). Bierzemy część
        // do sekund i normalizujemy do formatu DATETIME MySQL.
        $dateCreated = null;
        $rawDate = $payload['date_created_gmt'] ?? $payload['date_created'] ?? null;
        if (is_string($rawDate) && $rawDate !== '') {
            $dateCreated = str_replace('T', ' ', substr($rawDate, 0, 19));
        }

        $fields = [
            'integration_id' => isset($payload['__integration_id']) ? (int) $payload['__integration_id'] : null,
            'order_number'   => (string) ($payload['number'] ?? $wooOrderId),
            'status'         => $payload['status'] ?? null,
            // Status PASE wyliczony z Woo - repozytorium ustawi go tylko gdy pusty
            // (przy pierwszym imporcie); później status PASE jest źródłem prawdy.
            'pase_status'    => OrderStatus::fromWoo($payload['status'] ?? null),
            'currency'       => $payload['currency'] ?? null,
            'total'          => isset($payload['total']) ? (float) $payload['total'] : null,
            'customer_name'  => $customerName !== '' ? $customerName : null,
            'customer_email' => $billing['email'] ?? null,
            'date_created'   => $dateCreated,
        ];

        // Stan płatności sprzed aktualizacji - zdarzenie „Zamówienie zostało opłacone” tylko przy przejściu na opłacone.
        $wasPaid = $this->ruleEngine !== null ? \Pase\Automation\OrderEvents::isPaidNow($this->repo->pdo(), $wooOrderId) : null;
        $isNew = $this->repo->upsert($wooOrderId, $fields, $payload);

        // Nadaj własny numer PASE, jeśli zamówienie jeszcze go nie ma.
        $this->repo->assignNumberIfMissing($wooOrderId);

        // Same produkty wirtualne -> „Zamówienie wirtualne — bez wysyłki” (bez nadpisywania wyboru z karty).
        (new \Pase\Services\VirtualProducts($this->repo->pdo()))->applyToOrder($wooOrderId, (array) ($payload['line_items'] ?? []));

        Logger::info(
            'WooOrderImport: ' . ($isNew ? 'zaimportowano' : 'zaktualizowano') . " zamówienie #{$wooOrderId}",
            ['status' => $fields['status'], 'total' => $fields['total']]
        );

        // Stan faktyczny: sprzedaż w sklepie odejmujemy raz, anulowanie oddaje (stan Woo pilnuje sam sklep).
        if ($this->actualStock !== null) {
            try {
                $this->actualStock->applyWooOrder((int) ($fields['integration_id'] ?? 0), $wooOrderId, $payload);
            } catch (\Throwable $e) {
                Logger::error("WooOrderImport: stan faktyczny dla #{$wooOrderId} nie zmieniony", ['error' => $e->getMessage()]);
            }
        }

        // Nieudana płatność w sklepie -> status CRM Anulowane (tylko w CRM, raz, tylko z „Nowe").
        try {
            $this->paymentFailure?->apply($wooOrderId);
        } catch (\Throwable $e) {
            Logger::error("WooOrderImport: nieudana płatność #{$wooOrderId} - status nie zmieniony", ['error' => $e->getMessage()]);
        }

        // Silnik automatyzacji - zdarzenie 'Pobrano zamówienie', raz na zamówienie.
        if ($this->ruleEngine !== null && !$this->repo->automationsDone($wooOrderId)) {
            $this->ruleEngine->dispatch('order.imported', $wooOrderId, $payload);
            $this->repo->markAutomationsDone($wooOrderId);
        }
        if ($this->ruleEngine !== null) {
            \Pase\Automation\OrderEvents::paidTransition($this->repo->pdo(), $wooOrderId, $wasPaid, $this->ruleEngine);
        }
    }
}
