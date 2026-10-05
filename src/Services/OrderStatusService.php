<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Automation\RuleEngine;
use Pase\Domain\OrderStatus;
use Pase\Plugin\Contract\OrderSource;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroPlugin;

/**
 * Zmiana statusu zamówienia PASE (panel: szczegóły + zbiorcza zmiana na liście).
 *
 * Multi-integracja: sync do Woo używa config sklepu, do którego należy zamówienie
 * (woo_orders.integration_id). Sync wykonywany tylko gdy w konfiguracji tej
 * integracji włączono sync_status. Sync jest best-effort.
 *
 * Allegro to osobna ścieżka (nie przez generyczny PluginRegistry jak Woo) - jego
 * klient wymaga PDO (tokeny w tabeli integrations) i globalnego configu z .env/settings
 * (config/config.php), a nie configu konta jak reszta integracji - patrz AllegroPlugin.
 */
final class OrderStatusService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IntegrationAccountRepository $integrations,
        private readonly OrderStatusRepository $statuses,
        private readonly array $allegroConfig = []
    ) {}

    public function change(int $wooOrderId, string $newStatus): string
    {
        // Dopuszczamy każdy status istniejący w bazie (systemowy lub własny).
        if (!$this->statuses->keyExists($newStatus)) {
            return 'Nieprawidłowy status.';
        }

        // Zapis lokalny (status PASE = źródło prawdy).
        (new AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.status_changed', ['pase_status' => $newStatus]);
        Logger::info("Status zmieniony: zamówienie #{$wooOrderId} -> {$newStatus}");

        // KOLEJNOŚĆ MA ZNACZENIE: najpierw wysyłamy ten status do kanału (Allegro/Woo),
        // dopiero potem odpalamy 'Ustawiono status'. Reguły mogą ustawić kolejny status -
        // wtedy on idzie do kanału jako ostatni i kanał kończy na tym samym statusie co CRM.
        // Przed pętlą reguł chroni RuleEngine (setStatus / MAX_DEPTH).
        $msg = $this->syncToChannel($wooOrderId, $newStatus);
        $this->dispatchStatusChanged($wooOrderId, $newStatus);
        return $msg . $this->propagateToMerged($wooOrderId, $newStatus);
    }

    /**
     * Zamówienia dołączone do tego (scalanie - OrderMerge) idą w tej samej paczce, więc dostają ten sam
     * status i ten sam status idzie do ich kanału. Bez reguł automatyzacji - klient dostałby dwa razy
     * ten sam e-mail, a reguły z dokumentami wystawiłyby dokument drugi raz.
     */
    private function propagateToMerged(int $wooOrderId, string $newStatus): string
    {
        $ids = (new OrderMerge($this->pdo))->propagationTargets($wooOrderId, $newStatus);
        $failed = 0;
        foreach ($ids as $id) {
            (new AuditTrail($this->pdo))->changeOrder($id, 'order.status_changed', ['pase_status' => $newStatus]);
            if (str_contains($this->syncToChannel($id, $newStatus), 'nie powiódł')) {
                $failed++;
            }
        }
        if ($ids === []) {
            return '';
        }
        return ' Ten sam status dostały scalone zamówienia (' . count($ids) . ')'
            . ($failed > 0 ? ", ale sync {$failed} z nich nie powiódł się (zob. logi)." : '.');
    }

    /** Odsyła status do kanału, z którego pochodzi zamówienie (jeśli sync włączony). */
    private function syncToChannel(int $wooOrderId, string $newStatus): string
    {

        // Ustal integrację zamówienia.
        $intStmt = $this->pdo->prepare('SELECT integration_id FROM woo_orders WHERE woo_order_id = ?');
        $intStmt->execute([$wooOrderId]);
        $integrationId = (int) $intStmt->fetchColumn();
        if ($integrationId <= 0) {
            return 'Zmieniono tylko w CRM - zamówienie nie jest przypisane do żadnej integracji, więc nie ma gdzie wysłać statusu.';
        }

        $integration = $this->integrations->find($integrationId);
        if ($integration === null) {
            return "Zmieniono tylko w CRM - integracja #{$integrationId} tego zamówienia nie istnieje.";
        }

        $cfg = $integration['config'] ?? [];
        if (empty($cfg['sync_status'])) {
            return 'Zmieniono tylko w CRM - w integracji „' . ($integration['name'] ?? $integration['type'])
                . '" wyłączona jest synchronizacja statusu (Integracje → edycja → synchronizuj status).';
        }

        // Allegro: osobna ścieżka, patrz komentarz przy klasie.
        if ($integration['type'] === 'allegro') {
            return $this->syncToAllegro($wooOrderId, $newStatus);
        }

        // Sync przez wtyczkę będącą źródłem zamówień (OrderSource mapuje status PASE -> kanał).
        $plugin = PluginRegistry::forAccount($integration['type'], $cfg);
        if (!$plugin instanceof OrderSource) {
            return 'Zmieniono w CRM (integracja nie wspiera synchronizacji statusu).';
        }

        $ok = $plugin->pushOrderStatus((string) $wooOrderId, $newStatus);
        return $ok
            ? 'Zmieniono i zsynchronizowano ze sklepem.'
            : 'Zmieniono w CRM, ale sync do sklepu nie powiódł się (zob. logi).';
    }

    /**
     * Allegro identyfikuje zamówienie po własnym source_order_id (UUID checkout-formu
     * z payloadu), nie po naszym syntetycznym woo_order_id - patrz NewAllegroOrderHandler.
     */
    private function syncToAllegro(int $wooOrderId, string $newStatus): string
    {
        $allegroStatus = OrderStatus::toAllegro($newStatus);
        if ($allegroStatus === null) {
            return 'Zmieniono tylko w CRM - status „' . $newStatus . '" nie jest zmapowany na status Allegro, '
                . 'więc nic nie wysłano (Konfiguracja → Statusy zamówień → mapowanie do Allegro).';
        }

        $stmt = $this->pdo->prepare('SELECT order_number FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        $checkoutFormId = (string) $stmt->fetchColumn();
        if ($checkoutFormId === '') {
            return 'Zmieniono w CRM (brak ID zamówienia Allegro - sync niemożliwy).';
        }

        $client = AllegroPlugin::makeClient($this->pdo, $this->allegroConfig);
        $ok = $client->updateFulfillmentStatus($checkoutFormId, $allegroStatus);
        return $ok
            ? 'Zmieniono i zsynchronizowano z Allegro.'
            : 'Zmieniono w CRM, ale sync do Allegro nie powiódł się (zob. logi).';
    }

    /**
     * Odpala reguły zdarzenia 'status.changed'. Payload budujemy z wiersza zamówienia
     * (z nadpisanym nowym statusem). Best-effort - błąd automatyzacji nie blokuje zmiany statusu.
     */
    private function dispatchStatusChanged(int $wooOrderId, string $newStatus): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT payload FROM woo_orders WHERE woo_order_id = ?');
            $stmt->execute([$wooOrderId]);
            $payload = json_decode((string) $stmt->fetchColumn(), true) ?: [];
            $payload['pase_status'] = $newStatus;

            $engine = new RuleEngine($this->pdo, new AutomationRuleRepository($this->pdo), $this->allegroConfig);
            $engine->dispatch('status.changed', $wooOrderId, $payload);
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja status.changed (#{$wooOrderId}) nie powiodła się: " . $e->getMessage());
        }
    }

    /**
     * @param int[] $ids
     * @return array{0:int,1:int} [ok, błędy syncu]
     */
    public function changeBulk(array $ids, string $newStatus): array
    {
        if (!$this->statuses->keyExists($newStatus)) {
            return [0, 0];
        }
        $ok = 0;
        $syncErr = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $msg = $this->change($id, $newStatus);
            $ok++;
            if (str_contains($msg, 'nie powiódł')) {
                $syncErr++;
            }
        }
        return [$ok, $syncErr];
    }
}
