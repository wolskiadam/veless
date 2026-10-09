<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Plugin\Contract\OrderSource;
use Pase\Plugin\Contract\Warehouse;
use Pase\Plugin\PluginRegistry;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\AppMode;
use Pase\Support\Logger;

/**
 * Harmonogram cyklicznych przepływów. Worker (cron co minutę) woła run() na początku
 * każdego przebiegu. Dla każdego przepływu sprawdzamy, czy minął jego INTERWAŁ
 * (minuty, z settings) od ostatniego uruchomienia (znacznik czasu w settings).
 * Jeśli tak - przepływ KOLEJKUJE swoje zadania (te same job types co import ręczny)
 * i zapisuje nowy znacznik. Wyłączony przepływ (interwał 0) jest pomijany.
 *
 * JEDEN CRON NA WSZYSTKO. Scheduler obsługuje wszystkie kanały sprzedaży naraz -
 * WooCommerce (wtyczki spełniające OrderSource) i Allegro (Order Events API) - oraz
 * odświeża tokeny OAuth. Dzięki temu do działania całości wystarczy jeden wpis
 * w crontabie, uruchamiający cli/worker.php co minutę. Osobne skrypty
 * cli/poll_allegro_orders.php i cli/refresh_tokens.php nadal działają (zgodność ze
 * starszymi instalacjami), ale nie są już potrzebne - a gdyby ktoś je zostawił,
 * nic się nie zepsuje: kursor zdarzeń i klucze dedup w kolejce chronią przed
 * podwójnym importem.
 *
 * Klucze settings:
 *   SYNC_ORDERS_EVERY / SYNC_STOCK_EVERY / SYNC_PRICE_EVERY / SYNC_PRODUCTS_EVERY  (minuty; 0 = off)
 *   SYNC_TRACKING_EVERY - śledzenie przesyłek (domyślnie 60 min)
 *   SYNC_*_AT          - znacznik ostatniego uruchomienia (unix)
 *   TOKENS_REFRESH_AT  - znacznik ostatniej próby odświeżenia tokenów (unix)
 */
final class Scheduler
{
    /** Definicje przepływów: klucz => [setting interwału, setting znacznika]. */
    private const FLOWS = [
        'orders'   => ['SYNC_ORDERS_EVERY',   'SYNC_ORDERS_AT'],
        'stock'    => ['SYNC_STOCK_EVERY',    'SYNC_STOCK_AT'],
        'price'    => ['SYNC_PRICE_EVERY',    'SYNC_PRICE_AT'],
        'products' => ['SYNC_PRODUCTS_EVERY', 'SYNC_PRODUCTS_AT'],
        'tracking' => ['SYNC_TRACKING_EVERY', 'SYNC_TRACKING_AT'],
    ];

    /** Interwał domyślny (minuty), gdy nikt go jeszcze nie ustawił w panelu. */
    public const DEFAULT_EVERY = ['SYNC_TRACKING_EVERY' => '60'];

    /**
     * Odświeżanie tokenów NIE jest przepływem konfigurowalnym z panelu: to
     * konserwacja, bez której cała reszta przestaje działać. Co 5 minut wystarczy
     * z zapasem - token odnawiamy 15 minut przed wygaśnięciem.
     */
    private const TOKENS_AT_KEY       = 'TOKENS_REFRESH_AT';
    // Co ile odświeżać: TaskTimings 'TOKENS_EVERY' (domyślnie 5 min, zakres 1-10 min).

    /** @param array<string,mixed> $appConfig pełny config z config/config.php (potrzebna sekcja 'allegro') */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly Queue $queue,
        private readonly array $appConfig = []
    ) {}

    /** Uruchamia przepływy, którym minął interwał. @return array<string,int> nazwa => liczba zakolejkowanych */
    public function run(): array
    {
        $now = time();

        // Najpierw tokeny - zaraz potem przepływy będą z nich korzystać.
        $this->refreshTokens($now);

        // Procesy wstrzymane w panelu (System → Obciążenie serwera). Tokenów nie wstrzymujemy.
        $control = new ProcessControl($this->settings);

        // Harmonogram czasu wysyłki Allegro (Marketplace → Harmonogram czasu wysyłki) - sam pilnuje,
        // czy dziś już ustawiono; przy wielu ofertach kończy partiami w kolejnych minutach.
        if (!$control->isPaused('handling')) {
            try {
                $cfg = $this->allegroConfig();
                $r = (new AllegroHandlingSchedule($this->pdo, \PasePlugin\Allegro\AllegroPlugin::makeClient($this->pdo, $cfg)))->tick();
                if ($r['changed'] > 0) {
                    Logger::info('Scheduler[handling]: ' . $r['message']);
                }
            } catch (\Throwable $e) {
                Logger::warn('Scheduler[handling] błąd: ' . $e->getMessage());
            }
        }

        // Dashboard Allegro (jakość sprzedaży i finanse) - cache odświeżany co godzinę.
        if (!$control->isPaused('allegro_dashboard')
            && $now - (int) ($this->settings->get(AllegroDashboard::AT_KEY, '0') ?? '0') >= AllegroDashboard::REFRESH_EVERY_MIN * 60) {
            $this->settings->setMany([AllegroDashboard::AT_KEY => (string) $now]);
            try {
                $client = \PasePlugin\Allegro\AllegroPlugin::makeClient($this->pdo, $this->allegroConfig());
                if ($client->bearerToken() !== null) {
                    $r = (new AllegroDashboard($this->pdo))->refresh($client, $this->settings);
                    if (!$r['ok']) {
                        Logger::warn('Scheduler[allegro_dashboard]: ' . implode(' | ', $r['messages']));
                    }
                }
            } catch (\Throwable $e) {
                Logger::warn('Scheduler[allegro_dashboard] błąd: ' . $e->getMessage());
            }
        }

        // Marża: koszt zakupu zapisany na pozycjach nowych zamówień (tanie, lokalne — co minutę).
        try {
            (new OrderMargins($this->pdo))->syncPending(300);
        } catch (\Throwable $e) {
            Logger::warn('Scheduler[margins] błąd: ' . $e->getMessage());
        }

        // Zwroty klientów z Allegro - tylko pobranie i zapis; stan, korekty i zwroty pieniędzy robi operator.
        if (!$control->isPaused('allegro_returns')
            && $now - (int) ($this->settings->get(OrderReturns::SYNC_AT_KEY, '0') ?? '0') >= OrderReturns::SYNC_EVERY_MIN * 60) {
            $this->settings->setMany([OrderReturns::SYNC_AT_KEY => (string) $now]);
            try {
                $client = \PasePlugin\Allegro\AllegroPlugin::makeClient($this->pdo, $this->allegroConfig());
                if ($client->bearerToken() !== null) {
                    $r = (new OrderReturns($this->pdo))->syncAllegro($client);
                    if (!$r['ok']) {
                        Logger::warn('Scheduler[allegro_returns]: ' . $r['message']);
                    }
                }
            } catch (\Throwable $e) {
                Logger::warn('Scheduler[allegro_returns] błąd: ' . $e->getMessage());
            }
        }

        // Dyskusje, reklamacje i oceny z Allegro - tylko pobranie; odpowiedzi wysyła operator w Wiadomościach.
        if (!$control->isPaused('allegro_feedback')
            && $now - (int) ($this->settings->get(AllegroFeedback::SYNC_AT_KEY, '0') ?? '0') >= AllegroFeedback::SYNC_EVERY_MIN * 60) {
            $this->settings->setMany([AllegroFeedback::SYNC_AT_KEY => (string) $now]);
            try {
                $client = \PasePlugin\Allegro\AllegroPlugin::makeClient($this->pdo, $this->allegroConfig());
                if ($client->bearerToken() !== null) {
                    $r = (new AllegroFeedback($this->pdo))->sync($client);
                    // Rozmowy z Centrum wiadomości do listy w CRM (wyszukiwarka, archiwum).
                    $inbox = new AllegroInbox($this->pdo);
                    $ri = $inbox->sync($client);
                    if (!$ri['ok']) {
                        $r['ok'] = false;
                        $r['messages'][] = 'Wiadomości: ' . $ri['message'];
                    }
                    $inbox->autoArchive(TaskTimings::get($this->settings, 'ARCHIVE_AFTER_DAYS'));
                    $this->settings->setMany([AllegroFeedback::SYNC_ERROR_KEY => implode(' | ', $r['messages'])]);
                    if (!$r['ok']) {
                        Logger::warn('Scheduler[allegro_feedback]: ' . implode(' | ', $r['messages']));
                    }
                }
            } catch (\Throwable $e) {
                Logger::warn('Scheduler[allegro_feedback] błąd: ' . $e->getMessage());
            }
        }

        $out = [];
        foreach (self::FLOWS as $flow => [$everyKey, $atKey]) {
            $everyMin = (int) ($this->settings->get($everyKey, self::DEFAULT_EVERY[$everyKey] ?? '0') ?? '0');
            if ($everyMin <= 0) {
                continue; // przepływ wyłączony
            }
            if ($control->isPaused($flow)) {
                continue; // wstrzymany w panelu - znacznik nie rusza, więc po wznowieniu ruszy od razu
            }
            $lastAt = (int) ($this->settings->get($atKey, '0') ?? '0');
            if ($now - $lastAt < $everyMin * 60) {
                continue; // jeszcze nie czas
            }

            try {
                $n = match ($flow) {
                    'orders'   => $this->pullOrders(),
                    'stock'    => $this->pushStockPrice('stock'),
                    'price'    => $this->pushStockPrice('price'),
                    'products' => $this->pullProducts(),
                    'tracking' => $this->trackShipments(),
                    default    => 0,
                };
                $out[$flow] = $n;
                Logger::info("Scheduler[{$flow}]: zakolejkowano {$n} zadań.");
            } catch (\Throwable $e) {
                Logger::warn("Scheduler[{$flow}] błąd: " . $e->getMessage());
            }
            // Znacznik aktualizujemy ZAWSZE (nawet przy 0/wyjątku), by nie kręcić w kółko co minutę.
            $this->settings->setMany([$atKey => (string) $now]);
        }
        return $out;
    }

    /**
     * Konserwacja: odnowienie tokenów OAuth bliskich wygaśnięcia.
     *
     * Znacznik zapisujemy PRZED próbą, nie po - gdyby odświeżanie rzuciło wyjątkiem
     * albo zawisło, kolejny przebieg workera (za minutę) nie powtarzałby go w kółko.
     */
    private function refreshTokens(int $now): void
    {
        $lastAt = (int) ($this->settings->get(self::TOKENS_AT_KEY, '0') ?? '0');
        if ($now - $lastAt < TaskTimings::get($this->settings, 'TOKENS_EVERY') * 60) {
            return;
        }
        $this->settings->setMany([self::TOKENS_AT_KEY => (string) $now]);

        try {
            $n = (new TokenRefresher($this->pdo, $this->allegroConfig()))->run();
            if ($n > 0) {
                Logger::info("Scheduler[tokens]: odświeżono {$n} tokenów.");
            }
        } catch (\Throwable $e) {
            Logger::warn('Scheduler[tokens] błąd: ' . $e->getMessage());
        }
    }

    /**
     * Cykliczne pobranie NOWYCH zamówień ze wszystkich aktywnych kanałów sprzedaży.
     *
     * Sklepy (WooCommerce): „tylko nowsze niż ostatni import" - bierzemy
     * MAX(date_created) danej integracji jako parametr `after` i kolejkujemy
     * woo.order.import (dedup chroni przed duplikatami).
     *
     * Allegro: osobna ścieżka, bo tam nie ma stronicowanej listy „po dacie" -
     * jest strumień zdarzeń z kursorem (patrz AllegroOrderPoller).
     */
    private function pullOrders(): int
    {
        $orders = new WooOrderRepository($this->pdo);
        $queued = 0;

        foreach ($this->orderSourceAccounts() as $acc) {
            $plugin = PluginRegistry::forAccount($acc['type'], $acc['config'] ?? []);
            if (!$plugin instanceof OrderSource) {
                continue;
            }
            if (AppMode::isLocal() && method_exists($plugin, 'fetchOrdersModifiedSince')) {
                $queued += $this->pullModifiedOrders($plugin, $acc);
                continue;
            }
            $after = $orders->latestOrderDate((int) $acc['id']);
            // Pobieramy do kilku stron (bezpieczny limit na jeden tik schedulera).
            for ($page = 1; $page <= 5; $page++) {
                $batch = $plugin->fetchOrders($page, 50, $after);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $order) {
                    $id = (int) ($order['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $status = $order['status'] ?? 'unknown';
                    $order['__integration_id'] = (int) $acc['id'];
                    if ($this->queue->enqueue(
                        'woo.order.import',
                        $order,
                        "woo.order.import:{$acc['id']}:{$id}:{$status}"
                    )) {
                        $queued++;
                    }
                }
            }
        }

        // Allegro w osobnym try - awaria marketplace'u nie może wywrócić importu ze sklepu.
        try {
            $queued += $this->pullAllegroOrders();
        } catch (\Throwable $e) {
            Logger::warn('Scheduler[orders] Allegro: ' . $e->getMessage());
        }

        return $queued;
    }

    /**
     * Jednorazowe dociągnięcie zamówień zmienionych od $sinceGmt we wszystkich sklepach, które to
     * potrafią - po przekazaniu pracy między online a komputerem (Services\Handover), bo webhooki
     * z tego czasu trafiały do wstrzymanej strony. @return int liczba zakolejkowanych zadań
     */
    public function pullModifiedSince(string $sinceGmt): int
    {
        $queued = 0;
        foreach ($this->orderSourceAccounts() as $acc) {
            $plugin = PluginRegistry::forAccount($acc['type'], $acc['config'] ?? []);
            if (!$plugin instanceof OrderSource || !method_exists($plugin, 'fetchOrdersModifiedSince')) {
                continue;
            }
            $this->settings->setMany(['LOCAL_ORDERS_CURSOR_' . (int) $acc['id'] => $sinceGmt]);
            $queued += $this->pullModifiedOrders($plugin, $acc);
        }
        return $queued;
    }

    /**
     * Tryb lokalny (AppMode): bez webhooków nikt nie powie CRM-owi, że zamówienie w sklepie
     * zmieniło status (np. opłacone), więc zamiast „nowsze niż ostatnie" bierzemy wszystko,
     * co zmieniło się od kursora (data modyfikacji GMT, per konto). Kursor cofamy o sekundę,
     * żeby nie zgubić zamówień zmienionych w tej samej sekundzie - powtórki zatrzymuje dedup
     * (ten sam klucz co webhook i harmonogram: id + status).
     *
     * @param array<string,mixed> $acc
     */
    private function pullModifiedOrders(object $plugin, array $acc): int
    {
        $key = 'LOCAL_ORDERS_CURSOR_' . (int) $acc['id'];
        // Pierwszy przebieg: ostatnia doba. Starszą historię ściąga Zamówienia → Importuj.
        $since = (string) ($this->settings->get($key, '') ?? '');
        if ($since === '') {
            $since = gmdate('Y-m-d\TH:i:s', time() - 86400);
        }
        $queued = 0;
        $newest = null;
        for ($page = 1; $page <= 5; $page++) {
            $batch = $plugin->fetchOrdersModifiedSince($page, 50, $since);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $order) {
                $id = (int) ($order['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $modified = (string) ($order['date_modified_gmt'] ?? '');
                if ($modified !== '' && ($newest === null || $modified > $newest)) {
                    $newest = $modified;
                }
                $status = $order['status'] ?? 'unknown';
                $order['__integration_id'] = (int) $acc['id'];
                if ($this->queue->enqueue('woo.order.import', $order, "woo.order.import:{$acc['id']}:{$id}:{$status}")) {
                    $queued++;
                }
            }
        }
        if ($newest !== null) {
            $this->settings->setMany([$key => gmdate('Y-m-d\TH:i:s', strtotime($newest . ' UTC') - 1)]);
        }
        return $queued;
    }

    /** Nowe zamówienia Allegro (Order Events API). 0 gdy konto nie jest połączone. */
    private function pullAllegroOrders(): int
    {
        return (new AllegroOrderPoller($this->pdo, $this->settings, $this->queue, $this->allegroConfig()))
            ->poll();
    }

    /**
     * Śledzenie przesyłek u przewoźników (API Allegro, InPost, wtyczki kurierskie ze śledzeniem jak ORLEN Paczka).
     * Działa bezpośrednio, bez kolejki -
     * to kilka zapytań po max 20 numerów. Zwraca liczbę zmian etapu (każda odpala reguły
     * 'shipment.status'). 0 gdy konto Allegro nie jest połączone.
     */
    private function trackShipments(): int
    {
        $cfg = $this->allegroConfig();
        $client = \PasePlugin\Allegro\AllegroPlugin::makeClient($this->pdo, $cfg);
        return (new ShipmentTracking($this->pdo, $client, $cfg))->run();
    }

    /** Cykliczne odświeżenie katalogu produktów (woo.product.import) ze sklepów. */
    private function pullProducts(): int
    {
        $queued = 0;
        foreach ($this->warehouseAccounts() as $acc) {
            $plugin = PluginRegistry::forAccount($acc['type'], $acc['config'] ?? []);
            if (!$plugin instanceof Warehouse) {
                continue;
            }
            // Waga i wymiary przychodzą w jednostkach sklepu - bez nich import zapisałby 240 g jako 240 kg.
            $units = null;
            if (method_exists($plugin, 'client')) {
                try {
                    $units = \Pase\Handlers\WooProductImportHandler::shopUnits($plugin->client(), $acc['config'] ?? []);
                } catch (\Throwable $e) {
                    $units = null;
                }
            }
            if ($units === null) {
                continue;   // bez jednostek nie odświeżamy - lepiej stare dane niż zepsuta waga
            }
            for ($page = 1; $page <= 5; $page++) {
                $batch = $plugin->fetchProducts($page, 50);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $prod) {
                    $id  = (int) ($prod['id'] ?? 0);
                    $sku = trim((string) ($prod['sku'] ?? ''));
                    if ($id <= 0 || $sku === '') {
                        continue;
                    }
                    $prod['__integration_id'] = (int) $acc['id'];
                    [$prod['__weight_unit'], $prod['__dim_unit']] = $units;
                    if ($this->queue->enqueue(
                        'woo.product.import',
                        $prod,
                        "woo.product.import:{$acc['id']}:{$id}"
                    )) {
                        $queued++;
                    }
                }
            }
        }
        return $queued;
    }

    /**
     * Cykliczny wypych stanów/cen PASE -> sklep. Kolejkuje woo.stock.push per produkt
     * (handler wypycha stan i/lub cenę zależnie od pola 'what'). Tu tylko kolejkujemy
     * te produkty, które mają powiązanie ze sklepem (woo_product_id).
     *
     * @param 'stock'|'price' $what
     */
    private function pushStockPrice(string $what): int
    {
        $ids = (new ProductRepository($this->pdo))->pushableIds(2000);
        $queued = 0;
        foreach ($ids as $pid) {
            if ($this->queue->enqueue(
                'woo.stock.push',
                ['product_id' => $pid, 'what' => $what],
                // Dedup per produkt+rodzaj: nie dubluj, jeśli poprzednie jeszcze czeka.
                "woo.stock.push:{$what}:{$pid}"
            )) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Aktywne integracje będące źródłem zamówień - dowolna wtyczka spełniająca
     * OrderSource, nie tylko WooCommerce. Allegro nie przechodzi tą drogą (nie ma
     * stronicowanego fetchOrders), obsługuje je pullAllegroOrders().
     */
    private function orderSourceAccounts(): array
    {
        $repo = new IntegrationAccountRepository($this->pdo);
        $out  = [];
        foreach ($repo->all() as $acc) {
            if ((int) ($acc['is_active'] ?? 0) !== 1) {
                continue;
            }
            $plugin = PluginRegistry::forAccount((string) $acc['type'], $acc['config'] ?? []);
            if ($plugin instanceof OrderSource) {
                $out[] = $acc;
            }
        }
        return $out;
    }

    private function warehouseAccounts(): array
    {
        return (new IntegrationAccountRepository($this->pdo))->activeByType('woocommerce');
    }

    /** @return array<string,mixed> sekcja 'allegro' z configu (pusta = Allegro pomijane) */
    private function allegroConfig(): array
    {
        $cfg = $this->appConfig['allegro'] ?? [];
        return is_array($cfg) ? $cfg : [];
    }
}
