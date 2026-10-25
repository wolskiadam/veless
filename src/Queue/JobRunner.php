<?php
declare(strict_types=1);

namespace Pase\Queue;

use PDO;
use Pase\Automation\RuleEngine;
use Pase\Handlers\NewAllegroOrderHandler;
use Pase\Handlers\StockSyncHandler;
use Pase\Handlers\WooOrderImportHandler;
use Pase\Handlers\WooProductImportHandler;
use Pase\Handlers\WooStockPushHandler;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderLogRepository;
use Pase\Repository\ProductMappingRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\WooOrderRepository;
use PasePlugin\Allegro\AllegroPlugin;

/**
 * Wykonanie jednego zadania z kolejki według jego typu - wspólne dla workera (cli/worker.php)
 * i przycisku „Pobierz zamówienia teraz” na liście zamówień (orders_sync_now.php), który
 * przetwarza świeżo pobrane zamówienia od razu, zamiast czekać na następny przebieg crona.
 *
 * Handlery składamy leniwie: przycisk potrzebuje tylko importu zamówień, a nie klienta stanów Allegro.
 */
final class JobRunner
{
    /** Typy zadań, które dopisują zamówienia do listy - te przetwarza przycisk „Pobierz zamówienia teraz”. */
    public const ORDER_IMPORT_TYPES = ['woo.order.import', 'allegro.order.new'];

    /** @var array<string,object> */
    private array $handlers = [];
    private ?RuleEngine $ruleEngine = null;

    /** @param array<string,mixed> $config pełny config z config/config.php (potrzebna sekcja 'allegro') */
    public function __construct(private readonly PDO $pdo, private readonly array $config = []) {}

    /** Silnik reguł automatyzacji - ten sam dla wszystkich handlerów (worker przekazuje go też TikTok/ERLI). */
    public function ruleEngine(): RuleEngine
    {
        return $this->ruleEngine ??= new RuleEngine($this->pdo, new AutomationRuleRepository($this->pdo), $this->allegroConfig());
    }

    /** @param array<string,mixed> $payload */
    public function handle(string $jobType, array $payload): void
    {
        $this->handler($jobType)->handle($payload);
    }

    private function handler(string $jobType): object
    {
        return $this->handlers[$jobType] ??= match ($jobType) {
            // Allegro zapisuje wprost do woo_orders (wspólna lista „Zamówienia”, źródło = Allegro). Patrz NewAllegroOrderHandler.
            'allegro.order.new' => new NewAllegroOrderHandler(new ProductRepository($this->pdo), new OrderLogRepository($this->pdo),
                new WooOrderRepository($this->pdo), new IntegrationAccountRepository($this->pdo), new Queue($this->pdo), $this->ruleEngine(),
                function (string $offerId): ?array {
                    static $client = null;
                    return ($client ??= AllegroPlugin::makeClient($this->pdo, $this->allegroConfig()))->productOffer($offerId);
                }),
            'woo.stock.sync' => new StockSyncHandler(new ProductMappingRepository($this->pdo),
                AllegroPlugin::makeClient($this->pdo, $this->allegroConfig())),
            'woo.order.import' => new WooOrderImportHandler(new WooOrderRepository($this->pdo), $this->ruleEngine(),
                new \Pase\Services\ActualStock($this->pdo), new \Pase\Services\PaymentFailure($this->pdo)),
            'woo.product.import' => new WooProductImportHandler(new ProductRepository($this->pdo),
                \Pase\Services\WooCustomFields::withShopMedia($this->pdo)),
            'woo.stock.push' => new WooStockPushHandler(new ProductRepository($this->pdo), new IntegrationAccountRepository($this->pdo)),
            default => throw new \RuntimeException("Nieznany typ zadania: {$jobType}"),
        };
    }

    /** @return array<string,mixed> */
    private function allegroConfig(): array
    {
        $cfg = $this->config['allegro'] ?? [];
        return is_array($cfg) ? $cfg : [];
    }
}
