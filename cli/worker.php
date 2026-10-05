<?php
declare(strict_types=1);

/**
 * Worker kolejki - ZASTĘPUJE BullMQ/Celery na shared hostingu.
 * Uruchamiaj cronem CO MINUTĘ:
 *
 *   * * * * * /usr/bin/php /home/USER/pase/cli/worker.php >> /home/USER/pase/storage/cron.log 2>&1
 *
 * To JEDYNY wymagany wpis w cronie. Worker odpala też Scheduler, który pobiera
 * zamówienia ze WSZYSTKICH kanałów (sklepy WooCommerce + Allegro) i odświeża
 * tokeny OAuth - osobne wpisy dla poll_allegro_orders.php i refresh_tokens.php
 * nie są już potrzebne.
 *
 * Każdy przebieg:
 *   1. Odzyskuje zawieszone zadania (recoverStuck).
 *   2. Przetwarza do WORKER_BATCH_SIZE zadań.
 *   3. Routuje zadanie do odpowiedniego handlera.
 *   4. Retry + backoff obsługuje Queue::fail() (3 próby, wykładniczo).
 *   5. Między żądaniami do Allegro pilnuje ALLEGRO_MIN_INTERVAL_MS (rate-limit).
 *
 * Lock pliku zapobiega nakładaniu się przebiegów, gdy jeden trwa > 60s.
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

use Pase\Handlers\NewAllegroOrderHandler;
use Pase\Handlers\StockSyncHandler;
use Pase\Automation\RuleEngine;
use Pase\Handlers\WooOrderImportHandler;
use Pase\Handlers\WooProductImportHandler;
use Pase\Handlers\WooStockPushHandler;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Queue\Queue;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\IntegrationRepository;
use Pase\Repository\OrderLogRepository;
use Pase\Repository\ProductMappingRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroPlugin;

$config = require dirname(__DIR__) . '/config/config.php';

// --- Lock: nie pozwól dwóm przebiegom crona działać równolegle ---
// Jeśli poprzedni przebieg padł/zawiesił się bez zwolnienia locka (typowe na hostingu
// współdzielonym, np. zawodny flock() na sieciowym systemie plików, albo zawieszone
// żądanie HTTP bez timeoutu) - kolejka rośnie w nieskończoność, bo KAŻDY następny cron
// natychmiast się poddaje, cicho, bez żadnego błędu w logu. Dlatego trzymamy w pliku
// znacznik czasu przejęcia locka i wymuszamy przejęcie, jeśli jest podejrzanie stary
// (worker biegnie co minutę, pojedynczy przebieg nie powinien trwać > kilku minut).
$lockFile = PASE_ROOT . '/storage/worker.lock';
$lock = fopen($lockFile, 'c+');
if ($lock === false) {
    exit(0);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    rewind($lock);
    $heldSince = (int) trim((string) fread($lock, 32));
    fclose($lock);
    $ageSeconds = $heldSince > 0 ? time() - $heldSince : 0;

    if ($ageSeconds < 600) {
        Logger::info('worker: poprzedni przebieg wciąż trwa - wychodzę');
        exit(0);
    }

    Logger::warn("worker: lock trzymany od {$ageSeconds}s - traktuję jako zawieszony po awarii, przejmuję");
    $lock = fopen($lockFile, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        Logger::error('worker: nie udało się przejąć zawieszonego locka - wychodzę');
        exit(0);
    }
}
ftruncate($lock, 0);
rewind($lock);
fwrite($lock, (string) time());
fflush($lock);

$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

// --- Sygnał życia crona ---
// Znacznik przebiegu zapisujemy NA POCZĄTKU, zanim cokolwiek zdąży się wywrócić.
// Dzięki temu strona Konfiguracja → Synchronizacja odróżnia "cron w ogóle nie
// chodzi" (znacznik stoi w miejscu) od "cron chodzi, ale przepływ jest wyłączony".
$settings = new SettingsRepository($pdo);
try {
    $settings->setMany(['WORKER_LAST_RUN_AT' => (string) time()]);
} catch (\Throwable $e) {
    Logger::warn('worker: nie mogę zapisać znacznika przebiegu: ' . $e->getMessage());
}

// --- Szyfrowanie sekretów w bazie: jawne tokeny/klucze (sprzed zmiany, z przywróconej kopii) -> zaszyfrowane ---
try {
    \Pase\Support\SecretStore::sealAll($pdo);
} catch (\Throwable $e) {
    Logger::warn('worker: szyfrowanie sekretów nie powiodło się: ' . $e->getMessage());
}

// --- Tryb lokalny (APP_MODE=local): jednorazowe ustawienia startowe, np. pobieranie zamówień co minutę ---
try {
    \Pase\Support\AppMode::applyLocalDefaults($settings);
} catch (\Throwable $e) {
    Logger::warn('worker: ustawienia trybu lokalnego: ' . $e->getMessage());
}

// --- Przekazanie pracy online ⇄ komputer (Services\Handover): zlecone zadanie, potem ewentualny postój ---
// Działa przed wstrzymaniem, bo wstrzymana instalacja też musi spakować albo przyjąć dane.
$handover = new \Pase\Services\Handover($pdo, $settings, PASE_ROOT);
try {
    $handover->tick();
} catch (\Throwable $e) {
    Logger::warn('worker: przekazanie danych: ' . $e->getMessage());
}
if ($handover->isAway()) {
    Logger::info('worker: praca przeniesiona na drugą instalację - kończę przebieg');
    exit(0);
}

// --- Wstrzymanie z panelu (System → Obciążenie serwera) ---
// Znacznik życia jest już zapisany, więc panel wie, że cron działa - tylko nic nie robi.
$control = new \Pase\Services\ProcessControl($settings);
if ($control->isPaused('worker')) {
    Logger::info('worker: praca w tle wstrzymana w panelu - kończę przebieg');
    exit(0);
}

// --- Złóż zależności (ręczny DI - bez kontenera, lekko dla shared hostingu) ---
$queue              = new Queue($pdo);
$products           = new ProductRepository($pdo);
$mappings           = new ProductMappingRepository($pdo);
$orderLogs          = new OrderLogRepository($pdo);
$integrations       = new IntegrationRepository($pdo);
$integrationAccounts = new IntegrationAccountRepository($pdo);
$wooOrders          = new WooOrderRepository($pdo);

$allegro = AllegroPlugin::makeClient($pdo, $config['allegro']); // klient Allegro z wtyczki

// Allegro nie tworzy już zamówienia w Woo (CRM jest głównym źródłem prawdy) - zamiast
// tego zapisuje wprost do woo_orders (wspólna lista "Zamówienia", źródło = Allegro)
// i odejmuje/wypycha stan magazynowy. Patrz NewAllegroOrderHandler.
$ruleEngine = new RuleEngine($pdo, new AutomationRuleRepository($pdo), $config['allegro'] ?? []);
$orderHandler = new NewAllegroOrderHandler($products, $orderLogs, $wooOrders, $integrationAccounts, $queue, $ruleEngine);
$stockHandler = new StockSyncHandler($mappings, $allegro);
$wooImportHandler = new WooOrderImportHandler($wooOrders, $ruleEngine, new \Pase\Services\ActualStock($pdo),
    new \Pase\Services\PaymentFailure($pdo));
$wooProductHandler = new WooProductImportHandler($products, \Pase\Services\WooCustomFields::withShopMedia($pdo));
$stockPushHandler = new WooStockPushHandler($products, $integrationAccounts);

// --- Odzysk zawieszonych zadań ---
$queue->recoverStuck(\Pase\Services\TaskTimings::get($settings, 'QUEUE_STUCK_MINUTES'));

// --- Auto-archiwizacja starych zamówień (częstotliwość i wiek: Konfiguracja → Synchronizacja) ---
try {
    $lastRun = (int) ($settings->get('ORDERS_AUTOARCHIVE_AT', '0') ?? '0');
    $archiveEvery = \Pase\Services\TaskTimings::get($settings, 'ARCHIVE_EVERY');      // min, 0 = wyłączona
    $archiveDays  = \Pase\Services\TaskTimings::get($settings, 'ARCHIVE_AFTER_DAYS');
    if ($archiveEvery > 0 && time() - $lastRun >= $archiveEvery * 60 && !$control->isPaused('archive')) {
        $archived = $wooOrders->autoArchiveOlderThan($archiveDays);
        $settings->setMany(['ORDERS_AUTOARCHIVE_AT' => (string) time()]);
        if ($archived > 0) {
            Logger::info("Auto-archiwizacja: przeniesiono {$archived} zamówień starszych niż {$archiveDays} dni.");
        }
    }
} catch (\Throwable $e) {
    Logger::warn('Auto-archiwizacja nie powiodła się: ' . $e->getMessage());
}

// --- Jednorazowo: zamówienia, które już miały nieudaną płatność, dostają status Anulowane (Services\PaymentFailure) ---
try {
    if ((string) $settings->get('PAYMENT_FAILED_SWEEP_DONE', '') !== '1') {
        $n = (new \Pase\Services\PaymentFailure($pdo))->sweep();
        $settings->setMany(['PAYMENT_FAILED_SWEEP_DONE' => '1']);
        Logger::info("Płatność nieudana: anulowano {$n} wcześniejszych zamówień (tylko w CRM).");
    }
} catch (\Throwable $e) {
    Logger::warn('Płatność nieudana: jednorazowe sprawdzenie nie powiodło się: ' . $e->getMessage());
}

// --- Czyszczenie kolejki: stare WYKONANE zadania (wiek i częstotliwość: Konfiguracja → Synchronizacja) ---
try {
    $keepDays = \Pase\Services\TaskTimings::get($settings, 'QUEUE_KEEP_DONE_DAYS');   // 0 = nie usuwaj
    $lastCleanup = (int) ($settings->get('QUEUE_CLEANUP_AT', '0') ?? '0');
    $cleanupEvery = \Pase\Services\TaskTimings::get($settings, 'QUEUE_CLEANUP_EVERY');   // min
    if ($keepDays > 0 && time() - $lastCleanup >= $cleanupEvery * 60 && !$control->isPaused('cleanup')) {
        $settings->setMany(['QUEUE_CLEANUP_AT' => (string) time()]);
        $purged = $queue->purgeDone($keepDays);
        if ($purged > 0) {
            Logger::info("Czyszczenie kolejki: usunięto {$purged} wykonanych zadań starszych niż {$keepDays} dni.");
        }
    }
} catch (\Throwable $e) {
    Logger::warn('Czyszczenie kolejki nie powiodło się: ' . $e->getMessage());
}

// --- Harmonogram: cykliczne pobieranie zamówień / sync stanów-cen / import produktów ---
try {
    // Scheduler ogarnia wszystkie kanały naraz (sklepy + Allegro) i odświeża tokeny,
    // dlatego do działania całości wystarczy TEN JEDEN cron.
    (new \Pase\Services\Scheduler($pdo, $settings, $queue, $config))->run();
} catch (\Throwable $e) {
    Logger::warn('Scheduler nie powiódł się: ' . $e->getMessage());
}

// --- PayU: nieopłacone linki do płatności - co 10 min sprawdź, czy klient zapłacił (gdyby powiadomienie PayU nie doszło) ---
try {
    // Tryb lokalny (Docker na komputerze): powiadomienia PayU nie dochodzą wcale, więc sprawdzamy co minutę.
    $payuEvery = \Pase\Support\AppMode::isLocal() ? 60 : 600;
    if (\Pase\Services\PayuPayouts::available() && time() - (int) $settings->get('PAYU_LINKS_AT', '0') >= $payuEvery) {
        $settings->setMany(['PAYU_LINKS_AT' => (string) time()]);
        \Pase\Services\PayuPayments::migrate($pdo);
        (new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo)))->refreshOpenLinks();
    }
} catch (\Throwable $e) {
    Logger::warn('PayU: sprawdzenie linków do płatności nie powiodło się: ' . $e->getMessage());
}

// --- TikTok Shop: co kilka minut zamówienia, stany CRM do TikTok i numery przesyłek (Services\TiktokShop) ---
try {
    if (\Pase\Services\TiktokShop::available() && !$control->isPaused('tiktokshop')
        && time() - (int) $settings->get('TIKTOKSHOP_RUN_AT', '0') >= \Pase\Services\TiktokShop::RUN_EVERY_MIN * 60) {
        $settings->setMany(['TIKTOKSHOP_RUN_AT' => (string) time()]);
        \Pase\Services\TiktokShop::migrate($pdo);
        $r = (new \Pase\Services\TiktokShop($pdo, null, $queue, $ruleEngine))->run();
        if (array_sum($r) > 0) {
            Logger::info('TikTok Shop: zamówienia ' . $r['orders'] . ', stany ' . $r['stock'] . ', numery przesyłek ' . $r['tracking']);
        }
    }
} catch (\Throwable $e) {
    Logger::warn('TikTok Shop: synchronizacja nie powiodła się: ' . $e->getMessage());
}

// Limit zadań na przebieg: z panelu (ograniczenie obciążenia) albo z konfiguracji.
$batchSize          = $control->batchSize((int) $config['worker']['batch_size']);
if ($control->isPaused('queue')) {
    Logger::info('worker: kolejka wstrzymana w panelu - zadania czekają');
    $batchSize = 0;
}
$allegroMinInterval = $config['worker']['allegro_min_interval']; // ms
$lastAllegroCallAt  = 0.0; // mikroczas ostatniego żądania do Allegro

$processed = 0;

for ($i = 0; $i < $batchSize; $i++) {
    $job = $queue->reserveNext();
    if ($job === null) {
        break; // brak gotowych zadań
    }

    $jobId   = (int) $job['id'];
    $jobType = $job['job_type'];
    $payload = json_decode($job['payload'], true) ?: [];

    Logger::info("worker: przetwarzam #{$jobId} [{$jobType}]");

    try {
        switch ($jobType) {
            case 'allegro.order.new':
                $orderHandler->handle($payload);
                break;

            case 'woo.stock.sync':
                // Rate-limit Allegro: utrzymaj minimalny odstęp między żądaniami.
                $lastAllegroCallAt = throttleAllegro($lastAllegroCallAt, $allegroMinInterval);
                $stockHandler->handle($payload);
                break;

            case 'woo.order.import':
                // Pobranie zamówienia z WooCommerce do tabeli woo_orders.
                $wooImportHandler->handle($payload);
                break;

            case 'woo.product.import':
                // Import produktu z WooCommerce do magazynu PASE (products).
                $wooProductHandler->handle($payload);
                break;

            case 'woo.stock.push':
                // Wypych stanu magazynowego PASE -> WooCommerce (gdy PASE master).
                $stockPushHandler->handle($payload);
                break;

            default:
                throw new \RuntimeException("Nieznany typ zadania: {$jobType}");
        }

        $queue->complete($jobId);
        $processed++;
    } catch (\Throwable $e) {
        // Retry + backoff: Queue::fail decyduje, czy ponowić czy oznaczyć failed.
        $queue->fail($job, $e->getMessage());
    }
}

Logger::info("worker: przetworzono {$processed} zadań w tym przebiegu");

flock($lock, LOCK_UN);
fclose($lock);
exit(0);

/**
 * Pilnuje minimalnego odstępu (ms) między żądaniami do Allegro.
 * Zwraca zaktualizowany znacznik czasu ostatniego żądania.
 */
function throttleAllegro(float $lastCallAt, int $minIntervalMs): float
{
    if ($lastCallAt > 0.0) {
        $elapsedMs = (microtime(true) - $lastCallAt) * 1000;
        $waitMs = $minIntervalMs - $elapsedMs;
        if ($waitMs > 0) {
            usleep((int) ($waitMs * 1000));
        }
    }
    return microtime(true);
}
