<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\Courier;
use Pase\Plugin\Contract\CourierAsync;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Support\Logger;

/**
 * Rdzeń nadawania paczek przez wtyczki kurierskie (Capability::COURIER).
 *
 * Strona zamówienia pokazuje wybór „czym nadać": każde aktywne konto wtyczki kurierskiej,
 * która obsługuje dane zamówienie. Wtyczka może ograniczyć, dla jakich zamówień się nadaje,
 * publiczną metodą supportsOrder(array $order): bool (np. „Wysyłam z Allegro" - tylko
 * zamówienia z Allegro). Brak metody = nadaje się do każdego zamówienia.
 */
final class CourierShipping
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Konta kurierskie dostępne dla zamówienia.
     * @param array{row:array,payload:array} $order
     * @return array<int,array{account:array,plugin:Courier,name:string,icon:string,color:string,type:string}>
     */
    public function accountsForOrder(array $order): array
    {
        $out = [];
        foreach ((new IntegrationAccountRepository($this->pdo))->all() as $acc) {
            if (empty($acc['is_active'])) {
                continue;
            }
            $proto = PluginRegistry::get((string) $acc['type']);
            if ($proto === null) {
                continue;
            }
            try {
                $mf = $proto->manifest();
            } catch (\Throwable $e) {
                continue;
            }
            if (!$mf->hasCapability(Capability::COURIER)) {
                continue;
            }
            $plugin = PluginRegistry::forAccount((string) $acc['type'], $acc['config'] ?? []);
            if (!$plugin instanceof Courier) {
                continue;
            }
            if (method_exists($plugin, 'supportsOrder') && !$plugin->supportsOrder($order)) {
                continue;
            }
            if (!self::servesShop($acc, $order)) {
                continue;
            }
            $out[] = ['account' => $acc, 'plugin' => $plugin, 'name' => $mf->name, 'icon' => $mf->iconHtml(),
                      'color' => $mf->color, 'type' => $mf->type];
        }
        return $out;
    }

    /** Wtyczka kurierska konta (albo null). */
    /** Klucz sklepu w ustawieniu „Używaj dla zamówień ze sklepów”: zamówienia dodane w CRM (bez integracji). */
    public const MANUAL_SHOP = 'manual';

    /**
     * Czy konto kurierskie obsługuje zamówienie z tego sklepu. config['shops'] = lista id kont sklepów
     * (integration_accounts) i/lub MANUAL_SHOP; pusta lista = wszystkie sklepy. Dzięki temu np. dwa konta
     * ORLEN Paczka z różnymi nadawcami mogą obsługiwać dwa sklepy.
     * @param array<string,mixed> $account wiersz integration_accounts (z configiem)
     * @param array{row:array,payload:array} $order
     */
    public static function servesShop(array $account, array $order): bool
    {
        $shops = $account['config']['shops'] ?? [];
        if (!is_array($shops) || $shops === []) {
            return true;
        }
        $shopId = (int) ($order['row']['integration_id'] ?? 0);
        return in_array($shopId > 0 ? (string) $shopId : self::MANUAL_SHOP, array_map('strval', $shops), true);
    }

    /** Konto kurierskie po id (wiersz integration_accounts) albo null. */
    public function account(int $integrationId): ?array
    {
        return (new IntegrationAccountRepository($this->pdo))->find($integrationId);
    }

    public function pluginForAccount(int $integrationId): ?Courier
    {
        $acc = (new IntegrationAccountRepository($this->pdo))->find($integrationId);
        if ($acc === null) {
            return null;
        }
        $p = PluginRegistry::forAccount((string) $acc['type'], $acc['config'] ?? []);
        return $p instanceof Courier ? $p : null;
    }

    /**
     * Faktyczny koszt nadanych przesyłek od przewoźnika (opcjonalna metoda wtyczki
     * shipmentCost(externalId, order) - np. rozliczenia Allegro). Sprawdzamy tylko
     * przesyłki bez ceny z ostatnich 14 dni, żeby nie odpytywać API w nieskończoność.
     * @param array<int,array<string,mixed>> $shipments
     * @param array{row:array,payload:array} $order
     * @return bool czy coś się zmieniło
     */
    public function fillActualCosts(array $shipments, array $order): bool
    {
        $changed = false;
        $repo = new ShipmentRepository($this->pdo);
        foreach ($shipments as $sh) {
            if (($sh['status'] ?? '') !== 'created' || $sh['price'] !== null || empty($sh['bl_order_id'])
                || empty($sh['integration_id']) || strtotime((string) ($sh['created_at'] ?? 'now')) < strtotime('-14 days')) {
                continue;
            }
            $plugin = $this->pluginForAccount((int) $sh['integration_id']);
            if ($plugin === null || !method_exists($plugin, 'shipmentCost')) {
                continue;
            }
            try {
                $cost = $plugin->shipmentCost((string) $sh['bl_order_id'], $order);
            } catch (\Throwable $e) {
                continue;
            }
            if ($cost !== null && ($cost['amount'] ?? 0) > 0) {
                $repo->update((int) $sh['id'], ['price' => (float) $cost['amount']]);
                $changed = true;
            }
        }
        return $this->fillManualCost($shipments, $order) || $changed;
    }

    /**
     * Koszt przesyłki wpisanej ręcznie („Inne"): opłaty za wysyłkę, które przewoźnik/platforma
     * naliczyła dla całego zamówienia (wtyczka z metodą orderShipmentCost, np. rozliczenia Allegro).
     * Tylko gdy to jedyna nadana paczka zamówienia bez kosztu - opłaty dotyczą całego zamówienia,
     * więc nie przypisujemy ich drugi raz ani obok paczki nadanej przez integrację.
     * @param array<int,array<string,mixed>> $shipments
     * @param array{row:array,payload:array} $order
     */
    private function fillManualCost(array $shipments, array $order): bool
    {
        $target = null;
        foreach ($shipments as $sh) {
            if (($sh['status'] ?? '') !== 'created') {
                continue;
            }
            if ($sh['price'] !== null || !empty($sh['integration_id']) || $target !== null) {
                return false;
            }
            $target = $sh;
        }
        if ($target === null || strtotime((string) ($target['created_at'] ?? 'now')) < strtotime('-14 days')) {
            return false;
        }
        foreach ($this->accountsForOrder($order) as $co) {
            if (!method_exists($co['plugin'], 'orderShipmentCost')) {
                continue;
            }
            try {
                $cost = $co['plugin']->orderShipmentCost($order);
            } catch (\Throwable $e) {
                continue;
            }
            if ($cost !== null && ($cost['amount'] ?? 0) > 0) {
                (new ShipmentRepository($this->pdo))->update((int) $target['id'], ['price' => (float) $cost['amount']]);
                return true;
            }
        }
        return false;
    }

    /**
     * Dokańcza przesyłki 'pending' (przewoźnicy asynchroniczni, CourierAsync).
     * @param array<int,array<string,mixed>> $shipments
     * @return bool czy coś się zmieniło
     */
    public function resolvePending(array $shipments): bool
    {
        $changed = false;
        $repo = new ShipmentRepository($this->pdo);
        foreach ($shipments as $sh) {
            if (($sh['status'] ?? '') !== 'pending' || empty($sh['bl_order_id'])) {
                continue;
            }
            $plugin = $this->pluginForAccount((int) ($sh['integration_id'] ?? 0));
            if (!$plugin instanceof CourierAsync) {
                continue;
            }
            try {
                $r = $plugin->resolveShipment((string) $sh['bl_order_id']);
            } catch (\Throwable $e) {
                Logger::warn('Kurier: dokończenie przesyłki nie powiodło się - ' . $e->getMessage(), ['shipment' => $sh['id']]);
                continue;
            }
            if ($r['pending']) {
                continue;
            }
            $repo->update((int) $sh['id'], $r['ok']
                ? ['status' => 'created', 'bl_order_id' => $r['external_id'], 'waybill_no' => $r['waybill_no'],
                   'courier_code' => $r['courier_label'] ?? $sh['courier_code'], 'response_payload' => $r['raw'] ?? null]
                : ['status' => 'error', 'response_payload' => ['message' => $r['message'], 'raw' => $r['raw'] ?? null]]);
            if ($r['ok'] && !empty($sh['woo_order_id'])) {
                ShipmentTracking::fire($this->pdo, 'shipment.created', (int) $sh['woo_order_id'],
                    ['shipment_waybill' => (string) ($r['waybill_no'] ?? ''), 'shipment_carrier' => (string) ($r['courier_label'] ?? '')]);
            }
            $changed = true;
        }
        return $changed;
    }
}
