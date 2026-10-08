<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Auto-migracje schematu bazy.
 *
 * Idea (jak w sds-generator): zamiast ręcznego importu .sql, aplikacja przy
 * starcie sama dotwarza brakujące tabele przez CREATE TABLE IF NOT EXISTS.
 * Bezpieczne i idempotentne - istniejących tabel nie rusza, danych nie kasuje.
 *
 * UWAGA: to NIE modyfikuje plików aplikacji - tylko strukturę bazy, do której
 * i tak mamy dostęp z .env. Zmiany w kodzie nadal wgrywasz przez FTP.
 *
 * Plik sql/schema.sql pozostaje jako czytelna referencja schematu.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Uruchamia wszystkie migracje. Wołane raz na proces z config/database.php.
     * Tani guard: jeśli komplet tabel już istnieje, kończy natychmiast.
     */
    public function migrate(): void
    {
        \Pase\Services\AuditTrail::migrate($this->pdo);
        \Pase\Services\DocumentIssueGuard::migrate($this->pdo);
        \Pase\Automation\OrderEvents::migrate($this->pdo);   // automation_once - zdarzenia „raz na zamówienie”
        \Pase\Services\AllegroAds::migrate($this->pdo);
        \Pase\Services\AllegroDashboard::migrate($this->pdo);
        \Pase\Services\OrderReturns::migrate($this->pdo);
        \Pase\Services\AllegroFeedback::migrate($this->pdo);
        \Pase\Services\Customers::migrate($this->pdo);
        \Pase\Services\EmailLog::migrate($this->pdo);
        if (!$this->allTablesExist()) {
            // Każde CREATE TABLE osobno - inaczej jedna nieudana instrukcja (np. przez
            // ograniczenia hostingu) przerywała pętlę i blokowała utworzenie WSZYSTKICH
            // kolejnych tabel na liście na zawsze (guard allTablesExist() wciąż widziałby
            // brak kompletu i próbował od nowa, za każdym razem padając w tym samym miejscu).
            foreach ($this->statements() as $sql) {
                try {
                    $this->pdo->exec($sql);
                } catch (\Throwable $e) {
                    Logger::warn('Auto-migracja: nie udało się utworzyć tabeli - ' . $e->getMessage(), ['sql' => mb_substr($sql, 0, 120)]);
                }
            }
        }
        // Migracje kolumn biegną zawsze (idempotentnie) - dodają brakujące pola
        // do istniejących już tabel, czego guard powyżej by nie objął.
        $this->ensureColumns();
        try {
            \Pase\Services\OrderMargins::migrate($this->pdo);   // products.purchase_cost + migawki marży
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: marża - ' . $e->getMessage());
        }
        try {
            \Pase\Services\LowStock::migrate($this->pdo);   // products.min_stock + stan faktyczny (actual_stock)
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: products.min_stock - ' . $e->getMessage());
        }
        try {
            \Pase\Services\WooCustomFields::migrate($this->pdo);   // product_fields.woo_meta_key - pola pobierane ze sklepu
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: product_fields.woo_meta_key - ' . $e->getMessage());
        }
        try {
            \Pase\Services\Gs1::migrate($this->pdo);        // gs1_products - kopia kart z MojeGS1
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: gs1_products - ' . $e->getMessage());
        }
        try {
            \Pase\Services\PayuPayouts::migrate($this->pdo);   // payu_payouts - wypłaty zlecone z CRM
            \Pase\Services\PayuPayments::migrate($this->pdo);  // payu_order_payments, payu_refunds - płatności PayU przy zamówieniach
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: payu_payouts - ' . $e->getMessage());
        }
        try {
            \Pase\Services\OrderMerge::migrate($this->pdo);   // woo_orders.merged_into - scalone zamówienia
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: scalanie zamówień - ' . $e->getMessage());
        }
        try {
            \Pase\Services\OrderPayment::migrate($this->pdo);   // woo_orders.paid_amount - ręcznie potwierdzona wpłata
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: wpłaty - ' . $e->getMessage());
        }
        try {
            \Pase\Services\PlannedShipDate::migrate($this->pdo);   // woo_orders.planned_ship_date - planowana data nadania
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: planowana data nadania - ' . $e->getMessage());
        }
        try {
            \Pase\Services\ManualOrders::migrate($this->pdo);   // woo_orders.manual_shop_id - sklep zamówienia dodanego ręcznie
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: sklep zamówienia ręcznego - ' . $e->getMessage());
        }
        try {
            \Pase\Services\ManualOrders::detachReusedHistory($this->pdo);   // płatności PayU usuniętego zamówienia ręcznego z tym samym numerem
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: numery zamówień ręcznych - ' . $e->getMessage());
        }
        $this->backfillAllegroOrderItems();
        $this->clearStubInvoiceIds();
    }

    /**
     * Usunięta atrapa WfirmaService zapisywała przy każdym zamówieniu z Allegro
     * zmyślony numer faktury "INV-######" (6 cyfr), którego nie ma w wFirma.
     * Czyści te wpisy, żeby dziennik zamówień nie pokazywał nieistniejących faktur.
     * Idempotentne: po pierwszym przebiegu nie ma już czego zmieniać.
     */
    private function clearStubInvoiceIds(): void
    {
        try {
            $this->pdo->exec(
                "UPDATE order_logs SET wfirma_invoice_id = NULL
                 WHERE source_platform = 'allegro' AND wfirma_invoice_id REGEXP '^INV-[0-9]{6}$'"
            );
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: nie udało się wyczyścić atrap numerów faktur - ' . $e->getMessage());
        }
    }

    /**
     * Zamówienia z Allegro zapisane przed AllegroOrderMapper nie mają pozycji/sumy
     * w formacie Woo (strona zamówienia pokazywała "Brak pozycji", 0.00 PLN).
     * Idempotentne: rusza tylko wiersze z lineItems bez line_items.
     */
    private function backfillAllegroOrderItems(): void
    {
        try {
            $rows = $this->pdo->query(
                "SELECT woo_order_id, payload FROM woo_orders
                 WHERE payload LIKE '%\"lineItems\"%' AND payload NOT LIKE '%\"line_items\"%'"
            )->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                return;
            }
            $sku  = $this->pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
            $map  = $this->pdo->prepare('SELECT sku FROM product_mappings WHERE allegro_offer_id = ? LIMIT 1');
            $save = $this->pdo->prepare('UPDATE woo_orders SET payload = ?, total = ? WHERE woo_order_id = ?');
            foreach ($rows as $r) {
                $payload = json_decode((string) $r['payload'], true);
                if (!is_array($payload) || !AllegroOrderMapper::needsMapping($payload)) {
                    continue;
                }
                $matched = [];
                foreach ($payload['lineItems'] ?? [] as $idx => $li) {
                    $candidate = $li['offer']['external']['id'] ?? null;
                    $pid = null;
                    if ($candidate) {
                        $sku->execute([$candidate]);
                        $pid = $sku->fetchColumn() ?: null;
                    }
                    if (!$pid && !empty($li['offer']['id'])) {
                        $map->execute([(string) $li['offer']['id']]);
                        $mapped = $map->fetchColumn();
                        if ($mapped) {
                            $sku->execute([$mapped]);
                            $pid = $sku->fetchColumn() ?: null;
                            $candidate = $pid ? $mapped : $candidate;
                        }
                    }
                    $matched[$idx] = ['sku' => $candidate, 'product_id' => $pid ? (int) $pid : 0];
                }
                $payload = AllegroOrderMapper::withWooFields($payload, $matched);
                $save->execute([
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    (float) $payload['total'],
                    (int) $r['woo_order_id'],
                ]);
            }
            Logger::info('Auto-migracja: uzupełniono pozycje ' . count($rows) . ' zamówień z Allegro');
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: uzupełnianie pozycji Allegro nie powiodło się - ' . $e->getMessage());
        }
    }

    /**
     * Idempotentne dodanie brakujących kolumn. Sprawdza information_schema,
     * dodaje tylko to, czego nie ma. Bezpieczne do wielokrotnego uruchamiania.
     */
    private function ensureColumns(): void
    {
        // reserved_token: token rezerwacji zadania (TEXT). Wcześniej token był
        // błędnie wpisywany do reserved_at (DATETIME), przez co worker nie mógł
        // odczytać zarezerwowanego zadania i zostawiał je w stanie 'reserved'.
        if (!$this->columnExists('job_queue', 'reserved_token')) {
            $this->pdo->exec(
                "ALTER TABLE job_queue ADD COLUMN reserved_token VARCHAR(64) NULL AFTER reserved_at"
            );
        }

        // internal_status: status wewnętrzny PASE dla zamówienia Woo, ustawiany
        // przez silnik automatyzacji. Niezależny od statusu Woo (processing itd.).
        if (!$this->columnExists('woo_orders', 'internal_status')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN internal_status VARCHAR(64) NULL AFTER status"
            );
        }
        // automations_done: znacznik, że reguły zostały już przepuszczone (idempotencja).
        if (!$this->columnExists('woo_orders', 'automations_done')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN automations_done TINYINT(1) NOT NULL DEFAULT 0"
            );
        }

        // pase_status: JEDEN status systemu PASE (źródło prawdy). Status Woo
        // (kolumna `status`) jest mapowany na pase_status przy imporcie.
        // Zastępuje internal_status w roli statusu zamówienia.
        if (!$this->columnExists('woo_orders', 'pase_status')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN pase_status VARCHAR(32) NULL AFTER status"
            );
        }

        // integration_id: z której integracji (sklepu) pochodzi zamówienie.
        // Multi-integracja: jedno PASE może obsługiwać wiele sklepów Woo.
        if (!$this->columnExists('woo_orders', 'integration_id')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN integration_id INT UNSIGNED NULL AFTER woo_order_id"
            );
            $this->pdo->exec("ALTER TABLE woo_orders ADD KEY `idx_integration` (`integration_id`)");
        }

        // pase_number: WŁASNY numer zamówienia w systemie PASE (kolejny, unikalny,
        // niezależny od numeru w sklepie). Po dodaniu kolumny numerujemy istniejące
        // wg daty zamówienia (najstarsze = 1).
        if (!$this->columnExists('woo_orders', 'pase_number')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN pase_number INT UNSIGNED NULL AFTER id"
            );
            $this->pdo->exec("ALTER TABLE woo_orders ADD UNIQUE KEY `uq_pase_number` (`pase_number`)");
            $this->backfillPaseNumbers();
        }

        // Cykl życia zamówienia: active (lista) | archived (Archiwum) | trashed (Kosz).
        // Auto-archiwizacja po 3 mies. (worker) + ręczne usuwanie do kosza.
        if (!$this->columnExists('woo_orders', 'lifecycle')) {
            $this->pdo->exec(
                "ALTER TABLE woo_orders ADD COLUMN lifecycle VARCHAR(16) NOT NULL DEFAULT 'active'"
            );
            try {
                $this->pdo->exec("ALTER TABLE woo_orders ADD KEY `idx_lifecycle` (`lifecycle`)");
            } catch (\Throwable $e) {
                // indeks może już istnieć - pomijamy
            }
        }

        // Model wtyczkowy: type musi być ELASTYCZNY (dowolna wtyczka = dowolny typ).
        // Zamieniamy sztywny ENUM na VARCHAR, żeby nowe integracje nie wymagały ALTER-a.
        try {
            $col = $this->pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'integration_accounts' AND column_name = 'type'"
            )->fetchColumn();
            if ($col && stripos((string) $col, 'enum') !== false) {
                $this->pdo->exec(
                    "ALTER TABLE integration_accounts MODIFY COLUMN `type` VARCHAR(48) NOT NULL"
                );
            }
        } catch (\Throwable $e) {
            // tabela jeszcze nie istnieje / brak uprawnień - pomijamy
        }

        // bl_order_id w shipments (do pobierania etykiety getWaybill).
        if ($this->columnExists('shipments', 'waybill_no') && !$this->columnExists('shipments', 'bl_order_id')) {
            $this->pdo->exec("ALTER TABLE shipments ADD COLUMN bl_order_id VARCHAR(64) NULL AFTER waybill_no");
        }

        // Szczegóły ofert Allegro, których nie ma na liście ofert (czas wysyłki) - pamięć podręczna.
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `allegro_offer_details` (
                `offer_id` VARCHAR(32) NOT NULL,
                `handling_time` VARCHAR(12) NULL,
                `shipping_rate_id` VARCHAR(64) NULL,
                `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`offer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: allegro_offer_details - ' . $e->getMessage());
        }

        // Konta nadawcy e-mail (SMTP) - osobne dla każdego sklepu. Pierwsze konto przejmuje
        // dotychczasowe ustawienia MAIL_* (Konfiguracja → E-mail) jako domyślne.
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `mail_accounts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(120) NOT NULL,
                `from_email` VARCHAR(190) NOT NULL DEFAULT '',
                `from_name` VARCHAR(190) NOT NULL DEFAULT '',
                `reply_to` VARCHAR(190) NULL,
                `host` VARCHAR(190) NOT NULL DEFAULT '',
                `port` VARCHAR(8) NOT NULL DEFAULT '587',
                `user` VARCHAR(190) NOT NULL DEFAULT '',
                `pass` VARCHAR(255) NOT NULL DEFAULT '',
                `secure` VARCHAR(8) NOT NULL DEFAULT 'tls',
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if ((int) $this->pdo->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn() === 0) {
                $set = $this->pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'MAIL\\_%'")
                    ->fetchAll(\PDO::FETCH_KEY_PAIR);
                foreach ($set as $k => $v) { $set[$k] = SecretStore::openSetting((string) $k, $v, false); }
                if (($set['MAIL_SMTP_HOST'] ?? '') !== '' || ($set['MAIL_FROM_EMAIL'] ?? '') !== '') {
                    $this->pdo->prepare(
                        'INSERT INTO mail_accounts (name, from_email, from_name, host, port, user, pass, secure, is_default)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                    )->execute([
                        ($set['MAIL_FROM_NAME'] ?? '') !== '' ? $set['MAIL_FROM_NAME'] : 'Domyślne',
                        $set['MAIL_FROM_EMAIL'] ?? '', $set['MAIL_FROM_NAME'] ?? '', $set['MAIL_SMTP_HOST'] ?? '',
                        $set['MAIL_SMTP_PORT'] ?? '587', $set['MAIL_SMTP_USER'] ?? '', SecretStore::seal($set['MAIL_SMTP_PASS'] ?? '', 'mail_accounts.pass'),
                        $set['MAIL_SMTP_SECURE'] ?? 'tls',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: mail_accounts - ' . $e->getMessage());
        }
        // Link do opinii (np. Google) per konto e-mail sklepu - {{review_link}} w szablonie „Oceń nas".
        try {
            if (!$this->columnExists('mail_accounts', 'review_link')) {
                $this->pdo->exec("ALTER TABLE mail_accounts ADD COLUMN review_link VARCHAR(255) NULL AFTER reply_to");
            }
            // Logo sklepu do maili ({{shop_logo}}), base64 w bazie - przechodzi z kopią zapasową.
            if (!$this->columnExists('mail_accounts', 'logo_data')) {
                $this->pdo->exec("ALTER TABLE mail_accounts ADD COLUMN logo_mime VARCHAR(32) NULL, ADD COLUMN logo_data MEDIUMTEXT NULL");
            }
        } catch (\Throwable $e) {
            Logger::warn('Auto-migracja: mail_accounts.review_link - ' . $e->getMessage());
        }
        if ($this->columnExists('email_templates', 'subject') && !$this->columnExists('email_templates', 'mail_account_id')) {
            $this->pdo->exec("ALTER TABLE email_templates ADD COLUMN mail_account_id INT UNSIGNED NULL");
        }
        // Szablon z dołączanym PDF faktury z wFirma; podstawowy szablon „Faktura” od razu z załącznikiem.
        if ($this->columnExists('email_templates', 'subject') && !$this->columnExists('email_templates', 'attach_invoice')) {
            $this->pdo->exec("ALTER TABLE email_templates ADD COLUMN attach_invoice TINYINT(1) NOT NULL DEFAULT 0");
            $this->pdo->exec("UPDATE email_templates SET attach_invoice = 1 WHERE tpl_key = 'basic_invoice'");
        }

        // Punkt odbioru poprawiony ręcznie w CRM (ma pierwszeństwo przed danymi ze sklepu/Allegro).
        if ($this->columnExists('woo_orders', 'payload') && !$this->columnExists('woo_orders', 'local_pickup_point')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_pickup_point JSON NULL");
        }

        // Zamówienia przekazane automatyzacją „Przekaż zamówienie" (id konta => id zamówienia w sklepie).
        if ($this->columnExists('woo_orders', 'payload') && !$this->columnExists('woo_orders', 'forwarded_to')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN forwarded_to JSON NULL");
        }

        // Śledzenie przesyłek (ShipmentTracking): etap u przewoźnika + historia.
        if ($this->columnExists('shipments', 'waybill_no') && !$this->columnExists('shipments', 'tracking_status')) {
            $this->pdo->exec("ALTER TABLE shipments
                ADD COLUMN tracking_status VARCHAR(32) NULL,
                ADD COLUMN tracking_code VARCHAR(48) NULL,
                ADD COLUMN tracking_carrier VARCHAR(48) NULL,
                ADD COLUMN tracking_at DATETIME NULL,
                ADD COLUMN tracking_checked_at DATETIME NULL,
                ADD COLUMN tracking_events JSON NULL,
                ADD KEY idx_tracking (status, tracking_status)");
        }

        // Pełne dane produktu w magazynie (do wystawiania ofert na Allegro itp.).
        if ($this->columnExists('products', 'name')) {
            $productCols = [
                'description'       => 'MEDIUMTEXT NULL',         // opis pełny (HTML)
                'short_description' => 'TEXT NULL',               // opis krótki
                'images'            => 'JSON NULL',               // tablica URL-i zdjęć
                'categories'        => 'JSON NULL',               // kategorie
                'attributes'        => 'JSON NULL',               // atrybuty (kolor, rozmiar...)
                'ean'               => 'VARCHAR(64) NULL',        // EAN/GTIN
                'weight'            => 'DECIMAL(10,3) NULL',      // waga (kg)
                'length'            => 'DECIMAL(10,2) NULL',
                'width'             => 'DECIMAL(10,2) NULL',
                'height'            => 'DECIMAL(10,2) NULL',
                'custom_fields'     => 'JSON NULL',               // wartości pól własnych {klucz: wartość}
                'allegro_sections'  => 'JSON NULL',               // sekcje opisu na Allegro {klucz_sekcji: true|false}
            ];
            foreach ($productCols as $col => $type) {
                if (!$this->columnExists('products', $col)) {
                    $this->pdo->exec("ALTER TABLE products ADD COLUMN `{$col}` {$type}");
                }
            }
        }

        // categories w product_fields (przypisanie pola do kategorii produktów).
        if ($this->columnExists('product_fields', 'field_key') && !$this->columnExists('product_fields', 'categories')) {
            $this->pdo->exec("ALTER TABLE product_fields ADD COLUMN categories JSON NULL AFTER options");
        }

        // automation_rules: model zdarzenie + lista akcji (BaseLinker).
        if ($this->columnExists('automation_rules', 'conditions')) {
            if (!$this->columnExists('automation_rules', 'event')) {
                $this->pdo->exec(
                    "ALTER TABLE automation_rules ADD COLUMN `event` VARCHAR(48) NOT NULL DEFAULT 'order.imported' AFTER `name`"
                );
            }
            if (!$this->columnExists('automation_rules', 'actions')) {
                $this->pdo->exec("ALTER TABLE automation_rules ADD COLUMN `actions` JSON NULL AFTER `conditions`");
            }
            // Stara kolumna action_type była NOT NULL - rozluźniamy, by nowe reguły jej nie wymagały.
            try {
                $this->pdo->exec("ALTER TABLE automation_rules MODIFY COLUMN `action_type` VARCHAR(48) NULL");
            } catch (\Throwable $e) {
                // brak uprawnień / już NULL - pomijamy
            }
            $this->backfillAutomationActions();
            \Pase\Repository\AutomationRuleRepository::migrate($this->pdo);   // condition_match - wszystkie / dowolny warunek
        }

        // client_token w woo_orders - dostęp klienta do strony zamówienia bez logowania.
        if ($this->columnExists('woo_orders', 'woo_order_id') && !$this->columnExists('woo_orders', 'client_token')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN client_token CHAR(48) NULL");
            try {
                $this->pdo->exec("ALTER TABLE woo_orders ADD UNIQUE KEY uq_client_token (client_token)");
            } catch (\Throwable $e) {
                // klucz może już istnieć - pomijamy
            }
        }
        if ($this->columnExists('woo_orders', 'client_token')) {
            $this->backfillClientTokens();
        }

        // local_items/local_edited_at: lokalna (PASE-only) edycja pozycji zamówienia -
        // NIE wysyłana do sklepu. NULL = brak edycji, wyświetlamy oryginał z payloadu.
        if (!$this->columnExists('woo_orders', 'local_items')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_items JSON NULL");
        }
        if (!$this->columnExists('woo_orders', 'local_edited_at')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_edited_at DATETIME NULL");
        }

        // local_billing_email/phone: lokalna korekta danych kontaktowych klienta (PASE-only,
        // np. literówka przy zamówieniu) - NIE zmienia danych w sklepie ani weryfikacji klienta
        // na jego stronie zamówienia (ta sprawdza zawsze oryginał z payloadu).
        if (!$this->columnExists('woo_orders', 'local_billing_email')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_billing_email VARCHAR(255) NULL");
        }
        if (!$this->columnExists('woo_orders', 'local_billing_phone')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_billing_phone VARCHAR(64) NULL");
        }

        // no_shipping: zamówienie wirtualne oznaczone w CRM - nie wymaga wysyłki (Services\OrderIndicators).
        if (!$this->columnExists('woo_orders', 'no_shipping')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN no_shipping TINYINT(1) NULL");
        }

        // is_virtual: produkt wirtualny (bez wysyłki) - Services\VirtualProducts.
        if (!$this->columnExists('products', 'is_virtual')) {
            $this->pdo->exec("ALTER TABLE products ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0");
        }

        // local_invoice: poprawione w CRM dane do faktury (JSON, patrz Services\InvoiceData) - NIE wraca do sklepu.
        if (!$this->columnExists('woo_orders', 'local_invoice')) {
            $this->pdo->exec("ALTER TABLE woo_orders ADD COLUMN local_invoice TEXT NULL");
        }

        // image_path/image_name: opcjonalny obrazek (logo/grafika) do szablonu wydruku,
        // wstawiany przez placeholder {{obrazek}} - plik trzymany przez Pase\Services\Attachments.
        if (!$this->columnExists('print_templates', 'image_path')) {
            $this->pdo->exec("ALTER TABLE print_templates ADD COLUMN image_path VARCHAR(255) NULL");
        }
        if (!$this->columnExists('print_templates', 'image_name')) {
            $this->pdo->exec("ALTER TABLE print_templates ADD COLUMN image_name VARCHAR(255) NULL");
        }

        // woo_status / allegro_status: na jaki status kanału przekładać ten status PASE
        // przy odsyłaniu zmian. NULL = nie wysyłaj nic. Kierunek odwrotny (kanał -> PASE)
        // trzymamy w settings, bo tam jedno źródłowe ustawienie wskazuje jeden status
        // docelowy i nie da się zrobić niejednoznacznego przypisania.
        if (!$this->columnExists('order_statuses', 'woo_status')) {
            $this->pdo->exec("ALTER TABLE order_statuses ADD COLUMN woo_status VARCHAR(48) NULL AFTER label");
        }
        if (!$this->columnExists('order_statuses', 'allegro_status')) {
            $this->pdo->exec("ALTER TABLE order_statuses ADD COLUMN allegro_status VARCHAR(48) NULL AFTER woo_status");
        }

        // target: na którą drukarkę ma pójść zadanie - 'zebra' (surowe ZPL albo
        // grafika dla drukarki etykiet) czy 'a4' (zwykła drukarka przez sterownik
        // Windows). Wcześniej agent znał tylko jedną drukarkę, stąd domyślne 'zebra'
        // dla zadań sprzed tej zmiany.
        if (!$this->columnExists('print_jobs', 'target')) {
            $this->pdo->exec(
                "ALTER TABLE print_jobs ADD COLUMN target VARCHAR(16) NOT NULL DEFAULT 'zebra' AFTER kind"
            );
        }

        $this->seedFirstIntegration();
        $this->seedStatuses();
        $this->seedEmailTemplates();
        \Pase\Services\BasicEmailTemplates::seedOnce($this->pdo);
        $this->seedPrintTemplates();
    }

    /** Generuje brakujące tokeny klienta (po jednym na zamówienie). */
    private function backfillClientTokens(): void
    {
        try {
            $ids = $this->pdo->query(
                'SELECT woo_order_id FROM woo_orders WHERE client_token IS NULL'
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE woo_orders SET client_token = ? WHERE woo_order_id = ?');
        foreach ($ids as $id) {
            $stmt->execute([bin2hex(random_bytes(24)), (int) $id]);
        }
    }

    /**
     * Seed przykładowego szablonu e-mail (idempotentnie po tpl_key).
     * Pokazuje dostępne zmienne {{...}} - user może go zmienić lub usunąć.
     */
    private function seedEmailTemplates(): void
    {
        try {
            $body = "<p>Dzień dobry {{customer_name}},</p>\n"
                . "<p>Twoje zamówienie <strong>{{order_number}}</strong> jest w realizacji.</p>\n"
                . "<p>Wartość: {{total}} {{currency}}.</p>\n"
                . "<p>Pozdrawiamy,<br>{{shop_name}}</p>";
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO email_templates (tpl_key, name, subject, body, is_active)
                 VALUES (:k, :n, :s, :b, 1)"
            );
            $stmt->execute([
                ':k' => 'order_processing',
                ':n' => 'Zamówienie w realizacji',
                ':s' => 'Zamówienie {{order_number}} jest realizowane',
                ':b' => $body,
            ]);
        } catch (\Throwable $e) {
            // tabela jeszcze nie istnieje / brak uprawnień - pomijamy
        }
    }

    /**
     * Seed 3 przykładowych szablonów wydruku (tylko gdy tabela jest pusta - user
     * mógł już swoje pousuwać/zmienić, nie chcemy ich dokładać przy każdym uruchomieniu).
     * Pokazują dostępne placeholdery {{...}} - patrz Pase\Services\PrintTemplateRenderer.
     */
    private function seedPrintTemplates(): void
    {
        try {
            $exists = (int) $this->pdo->query('SELECT COUNT(*) FROM print_templates')->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return; // tabela jeszcze nie istnieje
        }
        if ($exists) {
            return;
        }

        $packingSlip = <<<'HTML'
<div style="font-family:sans-serif;font-size:13px;color:#22252b">
    <h1 style="font-size:20px;margin:0 0 4px">{{sklep}}</h1>
    <p style="color:#666;margin:0 0 18px">Karta zamówienia {{numer_pase}} (nr w sklepie: {{numer_sklepu}})</p>
    <table style="width:100%;margin-bottom:18px">
        <tr>
            <td style="vertical-align:top;width:50%">
                <strong>Adres dostawy</strong><br>
                <span style="white-space:pre-line">{{adres_dostawy}}</span>
            </td>
            <td style="vertical-align:top;width:50%">
                <strong>Dane zamówienia</strong><br>
                Data złożenia: {{data_zlozenia}}<br>
                Płatność: {{sposob_platnosci}}<br>
                E-mail: {{klient_email}}<br>
                Telefon: {{klient_telefon}}
            </td>
        </tr>
    </table>
    <table style="width:100%;border-collapse:collapse">
        <tr style="border-bottom:2px solid #22252b;text-align:left">
            <th style="padding:6px 4px">Produkt</th><th style="padding:6px 4px">SKU</th>
            <th style="padding:6px 4px;text-align:right">Ilość</th>
            <th style="padding:6px 4px;text-align:right">Cena</th>
            <th style="padding:6px 4px;text-align:right">Suma</th>
        </tr>
        {{produkty_tabela}}
    </table>
    <p style="text-align:right;font-size:15px;margin-top:14px"><strong>Razem: {{suma}} {{waluta}}</strong></p>
</div>
HTML;

        $productList = <<<'HTML'
<div style="font-family:sans-serif;font-size:13px;color:#22252b">
    <h1 style="font-size:20px;margin:0 0 4px">Spis produktów</h1>
    <p style="color:#666;margin:0 0 18px">Zamówienie {{numer_pase}} — {{klient_imie_nazwisko}} — {{liczba_pozycji}} pozycji</p>
    <table style="width:100%;border-collapse:collapse">
        <tr style="border-bottom:2px solid #22252b;text-align:left">
            <th style="padding:6px 4px">Produkt</th><th style="padding:6px 4px">SKU</th>
            <th style="padding:6px 4px;text-align:right">Ilość</th>
            <th style="padding:6px 4px;text-align:right">Cena</th>
            <th style="padding:6px 4px;text-align:right">Suma</th>
        </tr>
        {{produkty_tabela}}
    </table>
</div>
HTML;

        $zplLabel = <<<'ZPL'
^XA
^CF0,30
^FO30,30^FD{{sklep}}^FS
^CF0,22
^FO30,70^FDZamowienie {{numer_pase}}^FS
^FO30,100^FD{{klient_imie_nazwisko}}^FS
^FO30,130^FB400,4,0,L,0^FD{{adres_dostawy}}^FS
^FO30,260^BY2
^BCN,80,Y,N,N
^FD{{numer_sklepu}}^FS
^XZ
ZPL;

        $stmt = $this->pdo->prepare(
            'INSERT INTO print_templates (name, format, body, is_active, position) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute(['Karta zamówienia (A4)', 'A4', $packingSlip, 10]);
        $stmt->execute(['Spis produktów (A4)', 'A4', $productList, 20]);
        $stmt->execute(['Etykieta adresowa (Zebra ZPL)', 'ZPL', $zplLabel, 30]);
    }

    /**
     * Seed 5 statusów systemowych (idempotentnie). Odpowiadają stałym z OrderStatus
     * i ich mapowaniu z Woo. is_system=1 => nieusuwalne (user może zmienić kolor/nazwę).
     */
    private function seedStatuses(): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO order_statuses (status_key, label, color, position, is_system)
                 VALUES (:k, :l, :c, :p, 1)"
            );
        } catch (\Throwable $e) {
            return; // tabela jeszcze nie istnieje
        }
        $seed = [
            ['new',        'Nowe',         '#3498db', 10],
            ['processing', 'W realizacji', '#e67e22', 20],
            ['shipped',    'Wysłane',      '#27ae60', 30],
            ['cancelled',  'Anulowane',    '#e74c3c', 40],
            ['refunded',   'Zwrot',        '#7f8c8d', 50],
        ];
        foreach ($seed as [$k, $l, $c, $p]) {
            try {
                $stmt->execute([':k' => $k, ':l' => $l, ':c' => $c, ':p' => $p]);
            } catch (\Throwable $e) {
                // ignoruj pojedyncze błędy seedu
            }
        }
    }

    /**
     * Jednorazowa migracja: jeśli istnieją ustawienia Woo w `settings`, a nie ma
     * jeszcze żadnej integracji w `integration_accounts` - utwórz z nich
     * "integrację #1" i podepnij pod nią dotychczasowe zamówienia (integration_id NULL).
     * Idempotentne: działa tylko gdy brak integracji typu woocommerce.
     */
    private function seedFirstIntegration(): void
    {
        // Czy jest już jakakolwiek integracja Woo?
        try {
            $exists = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM integration_accounts WHERE type='woocommerce'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return; // tabela jeszcze nie istnieje - następnym razem
        }
        if ($exists > 0) {
            return;
        }

        // Zbierz ustawienia Woo z tabeli settings (stary model).
        $settings = [];
        try {
            $settings = $this->pdo->query('SELECT setting_key, setting_value FROM settings')
                ->fetchAll(\PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($settings as $k => $v) { $settings[$k] = SecretStore::openSetting((string) $k, $v, false); }
        } catch (\Throwable $e) {
            $settings = [];
        }
        $baseUrl = $settings['WOO_BASE_URL'] ?? '';
        if ($baseUrl === '') {
            return; // brak skonfigurowanego Woo - nie ma czego migrować
        }

        $config = json_encode(SecretStore::sealConfig([
            'base_url'        => $baseUrl,
            'consumer_key'    => $settings['WOO_CONSUMER_KEY'] ?? '',
            'consumer_secret' => $settings['WOO_CONSUMER_SECRET'] ?? '',
            'sync_status'     => ($settings['WOO_SYNC_STATUS'] ?? '0') === '1',
        ]), JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare(
            "INSERT INTO integration_accounts (type, name, is_active, config, webhook_secret)
             VALUES ('woocommerce', :name, 1, :config, :secret)"
        );
        $stmt->execute([
            ':name'   => 'Sklep WooCommerce',
            ':config' => $config,
            ':secret' => SecretStore::seal($settings['WOO_WEBHOOK_SECRET'] ?? null, 'integration_accounts.webhook_secret'),
        ]);
        $newId = (int) $this->pdo->lastInsertId();

        // Podepnij istniejące zamówienia bez integracji pod tę integrację.
        $this->pdo->prepare('UPDATE woo_orders SET integration_id = ? WHERE integration_id IS NULL')
            ->execute([$newId]);
    }

    /**
     * Jednorazowe ponumerowanie istniejących zamówień wg daty zamówienia
     * (najstarsze = 1). Wywoływane raz, tuż po dodaniu kolumny pase_number.
     */
    private function backfillPaseNumbers(): void
    {
        try {
            // Numeracja wg daty zamówienia rosnąco. Gdy date_created jest NULL,
            // używamy imported_at (COALESCE), bo NULL w ORDER BY ASC trafia na
            // początek i psuje kolejność. Remisy rozstrzyga id.
            $rows = $this->pdo->query(
                'SELECT id FROM woo_orders ORDER BY COALESCE(date_created, imported_at) ASC, id ASC'
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE woo_orders SET pase_number = ? WHERE id = ?');
        $n = 1;
        foreach ($rows as $id) {
            $stmt->execute([$n++, (int) $id]);
        }
    }

    /**
     * Przenosi stare reguły (pojedyncza action_type/action_params) do nowej kolumny
     * `actions` (lista [{type, params}]). Tylko gdy actions jest jeszcze puste.
     */
    private function backfillAutomationActions(): void
    {
        try {
            $rows = $this->pdo->query(
                "SELECT id, action_type, action_params FROM automation_rules
                 WHERE (actions IS NULL OR JSON_LENGTH(actions) = 0) AND action_type IS NOT NULL AND action_type <> ''"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE automation_rules SET actions = :a WHERE id = :id');
        foreach ($rows as $r) {
            $params = json_decode($r['action_params'] ?? '{}', true) ?: [];
            $actions = [['type' => $r['action_type'], 'params' => $params]];
            $stmt->execute([
                ':a'  => json_encode($actions, JSON_UNESCAPED_UNICODE),
                ':id' => (int) $r['id'],
            ]);
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function allTablesExist(): bool
    {
        $expected = ['integrations', 'product_mappings', 'order_logs', 'job_queue', 'woo_orders', 'settings', 'automation_rules', 'integration_accounts', 'order_statuses', 'user_layouts', 'shipments', 'products', 'product_fields', 'email_templates', 'order_messages', 'offer_templates', 'allegro_desc_templates', 'geocode_cache', 'print_templates', 'order_documents', 'print_jobs', 'print_assets'];
        $in = implode(',', array_fill(0, count($expected), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ($in)"
        );
        $stmt->execute($expected);
        return (int) $stmt->fetchColumn() === count($expected);
    }

    /**
     * Definicje tabel. Każdy element to samodzielny CREATE TABLE IF NOT EXISTS.
     * Trzymane tu (a nie tylko w .sql), żeby aplikacja mogła je wykonać sama.
     *
     * @return string[]
     */
    private function statements(): array
    {
        return [
            // 1. integrations
            "CREATE TABLE IF NOT EXISTS `integrations` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `platform_name` ENUM('allegro','woocommerce','wfirma') NOT NULL,
                `access_token` TEXT NULL,
                `refresh_token` TEXT NULL,
                `expires_at` DATETIME NULL,
                `webhook_secret` VARCHAR(255) NULL,
                `meta` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_platform` (`platform_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 2. product_mappings
            "CREATE TABLE IF NOT EXISTS `product_mappings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sku` VARCHAR(191) NOT NULL,
                `woo_product_id` BIGINT UNSIGNED NULL,
                `woo_variant_id` BIGINT UNSIGNED NULL,
                `allegro_offer_id` VARCHAR(64) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sku` (`sku`),
                KEY `idx_allegro_offer` (`allegro_offer_id`),
                KEY `idx_woo_product` (`woo_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 3. order_logs
            "CREATE TABLE IF NOT EXISTS `order_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `internal_status` ENUM('received','processing','woo_created','invoiced','completed','failed') NOT NULL DEFAULT 'received',
                `source_platform` ENUM('allegro','woocommerce','wfirma') NOT NULL,
                `source_order_id` VARCHAR(128) NOT NULL,
                `woo_order_id` BIGINT UNSIGNED NULL,
                `wfirma_invoice_id` VARCHAR(128) NULL,
                `last_error` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_source_order` (`source_platform`, `source_order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 4. job_queue
            "CREATE TABLE IF NOT EXISTS `job_queue` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job_type` VARCHAR(64) NOT NULL,
                `payload` JSON NOT NULL,
                `dedup_key` VARCHAR(191) NULL,
                `status` ENUM('pending','reserved','done','failed') NOT NULL DEFAULT 'pending',
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                `reserved_at` DATETIME NULL,
                `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_error` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_dedup` (`dedup_key`),
                KEY `idx_claim` (`status`, `available_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 5. woo_orders (migracja 002)
            "CREATE TABLE IF NOT EXISTS `woo_orders` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `woo_order_id` BIGINT UNSIGNED NOT NULL,
                `order_number` VARCHAR(64) NULL,
                `status` VARCHAR(32) NULL,
                `currency` VARCHAR(8) NULL,
                `total` DECIMAL(12,2) NULL,
                `customer_name` VARCHAR(255) NULL,
                `customer_email` VARCHAR(255) NULL,
                `date_created` DATETIME NULL,
                `payload` JSON NOT NULL,
                `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_woo_order` (`woo_order_id`),
                KEY `idx_status` (`status`),
                KEY `idx_date_created` (`date_created`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 6. settings - klucze API i ustawienia edytowalne z panelu.
            //    Pierwszeństwo nad .env (patrz config/config.php). Klucz-wartość.
            "CREATE TABLE IF NOT EXISTS `settings` (
                `setting_key` VARCHAR(64) NOT NULL,
                `setting_value` TEXT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 8. integration_accounts - WIELE integracji, także kilka tego samego typu
            //    (np. kilka sklepów WooCommerce). Każdy wiersz = jedno konto/sklep.
            //    config: JSON z kluczami specyficznymi dla typu (base_url, consumer_key...).
            "CREATE TABLE IF NOT EXISTS `integration_accounts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `type` VARCHAR(48) NOT NULL,
                `name` VARCHAR(160) NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `config` JSON NULL,
                `webhook_secret` VARCHAR(255) NULL,
                `access_token` TEXT NULL,
                `refresh_token` TEXT NULL,
                `expires_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_type_active` (`type`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 10. user_layouts - zapamiętany układ sekcji per użytkownik i widok
            //     (np. 'order_view'). layout = JSON z kolejnością sekcji w kolumnach.
            "CREATE TABLE IF NOT EXISTS `user_layouts` (
                `user_id` INT NOT NULL,
                `view_key` VARCHAR(48) NOT NULL,
                `layout` JSON NOT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `view_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 11. shipments - nadane przesyłki kurierskie (BLPaczka i przyszłe).
            "CREATE TABLE IF NOT EXISTS `shipments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `woo_order_id` BIGINT UNSIGNED NULL,
                `integration_id` INT UNSIGNED NULL,
                `courier_code` VARCHAR(48) NULL,
                `waybill_no` VARCHAR(128) NULL,
                `bl_order_id` VARCHAR(64) NULL,
                `order_ref` VARCHAR(128) NULL,
                `price` DECIMAL(10,2) NULL,
                `status` VARCHAR(48) NULL,
                `label_link` VARCHAR(512) NULL,
                `request_payload` JSON NULL,
                `response_payload` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`woo_order_id`),
                KEY `idx_waybill` (`waybill_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 12. products - MASTER produktów (centralny magazyn PASE).
            //     Źródło prawdy dla stanów/cen; powiązanie z produktem w sklepie.
            //     pase_stock/pase_price = wartości zarządzane w PASE.
            "CREATE TABLE IF NOT EXISTS `products` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sku` VARCHAR(191) NOT NULL,
                `name` VARCHAR(255) NULL,
                `pase_stock` INT NULL,
                `pase_price` DECIMAL(12,2) NULL,
                `source_integration_id` INT UNSIGNED NULL,
                `woo_product_id` BIGINT UNSIGNED NULL,
                `woo_variation_id` BIGINT UNSIGNED NULL,
                `payload` JSON NULL,
                `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sku` (`sku`),
                KEY `idx_woo_product` (`woo_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 13. product_fields - definicje WŁASNYCH pól produktu (globalne).
            //     Wartości trzymane per produkt w products.custom_fields (JSON).
            "CREATE TABLE IF NOT EXISTS `product_fields` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_key` VARCHAR(64) NOT NULL,
                `label` VARCHAR(120) NOT NULL,
                `type` VARCHAR(16) NOT NULL DEFAULT 'text',
                `options` JSON NULL,
                `categories` JSON NULL,
                `position` INT NOT NULL DEFAULT 100,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_field_key` (`field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 9. order_statuses - statusy zamówień PASE. 5 systemowych (zaseedowanych,
            //    nieusuwalnych, z mapowaniem Woo) + dowolne własne dodane przez usera.
            "CREATE TABLE IF NOT EXISTS `order_statuses` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `status_key` VARCHAR(48) NOT NULL,
                `label` VARCHAR(80) NOT NULL,
                `color` VARCHAR(16) NOT NULL DEFAULT '#888888',
                `position` INT NOT NULL DEFAULT 100,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_status_key` (`status_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 7. automation_rules - reguły 'ZDARZENIE + warunki TO lista akcji' (model BaseLinker).
            //    event:      klucz zdarzenia wyzwalającego (np. order.imported, status.changed).
            //    conditions: JSON listy warunków (łączone AND).
            //    actions:    JSON ponumerowanej listy akcji [{type, params}, ...].
            //    action_type/action_params - stare kolumny (pojedyncza akcja), trzymane dla zgodności wstecz.
            "CREATE TABLE IF NOT EXISTS `automation_rules` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(160) NOT NULL,
                `event` VARCHAR(48) NOT NULL DEFAULT 'order.imported',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `priority` INT NOT NULL DEFAULT 100,
                `conditions` JSON NOT NULL,
                `actions` JSON NULL,
                `action_type` VARCHAR(48) NULL,
                `action_params` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_event_active_priority` (`event`, `is_active`, `priority`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 14. email_templates - szablony e-mail (akcja automatyzacji send_email).
            //     body to HTML; wersję tekstową generujemy automatycznie przy wysyłce.
            //     Zmienne {{...}} podstawiane z danych zamówienia (patrz Mailer::render()).
            "CREATE TABLE IF NOT EXISTS `email_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tpl_key` VARCHAR(64) NOT NULL,
                `name` VARCHAR(160) NOT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `body` MEDIUMTEXT NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tpl_key` (`tpl_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 14b. print_templates - własne szablony wydruku (etykiety, karty zamówienia,
            //      listy pakowania...). format=A4/A5 -> HTML drukowany przez przeglądarkę;
            //      format=ZPL -> surowy tekst ZPL dla drukarek Zebra (bez PDF).
            //      body zawiera placeholdery {{...}} podstawiane per zamówienie.
            "CREATE TABLE IF NOT EXISTS `print_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(160) NOT NULL,
                `format` ENUM('A4','A5','ZPL') NOT NULL DEFAULT 'A4',
                `body` LONGTEXT NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `position` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 15. order_messages - wątek korespondencji z klientem per zamówienie.
            //     sender: 'client' (z publicznej strony) | 'staff' (z panelu).
            //     attachment_*: opcjonalny pojedynczy załącznik (plik w storage/uploads).
            "CREATE TABLE IF NOT EXISTS `order_messages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `woo_order_id` BIGINT NOT NULL,
                `sender` ENUM('client','staff') NOT NULL,
                `author_name` VARCHAR(160) NULL,
                `body` TEXT NOT NULL,
                `attachment_path` VARCHAR(255) NULL,
                `attachment_name` VARCHAR(255) NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order_created` (`woo_order_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 16. offer_templates - szablon oferty produktu na marketplace (Allegro itd.).
            //     data: JSON pól oferty (tytuł, opis, cena, stan, zdjęcia, kategoria, parametry).
            //     external_id: ID oferty po wystawieniu (na razie puste - draft w PASE).
            "CREATE TABLE IF NOT EXISTS `offer_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` INT UNSIGNED NOT NULL,
                `marketplace` VARCHAR(32) NOT NULL DEFAULT 'allegro',
                `data` JSON NOT NULL,
                `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
                `external_id` VARCHAR(64) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_product_market` (`product_id`, `marketplace`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 17. allegro_desc_templates - nazwane szablony OPISU oferty Allegro (sekcje/moduły).
            //     sections: JSON listy sekcji; sekcja = lista modułów [{type:TEXT|IMAGE, ...}]
            //     (zgodnie z formatem description.sections[].items[] w API Allegro).
            //     categories: JSON listy kategorii (z magazynu), do których szablon pasuje.
            "CREATE TABLE IF NOT EXISTS `allegro_desc_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(160) NOT NULL,
                `categories` JSON NULL,
                `sections` JSON NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 18. geocode_cache - wynik geokodowania (Nominatim) per zapytanie adresowe.
            //     Cachujemy, żeby nie odpytywać OSM przy każdym wejściu klienta na stronę.
            "CREATE TABLE IF NOT EXISTS `geocode_cache` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `query_hash` CHAR(40) NOT NULL,
                `query` VARCHAR(255) NOT NULL,
                `lat` DECIMAL(10,7) NULL,
                `lon` DECIMAL(10,7) NULL,
                `found` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_query_hash` (`query_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 19. order_documents - dokumenty sprzedaży wystawione dla zamówień.
            //     UNIQUE blokuje przypadkowe ponowne wystawienie tego samego typu.
            "CREATE TABLE IF NOT EXISTS `order_documents` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `woo_order_id` BIGINT UNSIGNED NOT NULL,
                `integration_id` INT UNSIGNED NOT NULL,
                `provider` VARCHAR(48) NOT NULL,
                `document_type` VARCHAR(32) NOT NULL,
                `remote_id` VARCHAR(128) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'issued',
                `message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_order_provider_type` (`woo_order_id`, `provider`, `document_type`),
                KEY `idx_remote` (`provider`, `remote_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 20. print_jobs - kolejka zadań druku dla lokalnego agenta (patrz
            //     public/print_agent_poll.php) - własny program na komputerze z drukarką
            //     Zebra (USB) odpytuje o zadania i drukuje bez ręcznego pobierania pliku.
            "CREATE TABLE IF NOT EXISTS `print_jobs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(32) NOT NULL DEFAULT 'label',
                `target` VARCHAR(16) NOT NULL DEFAULT 'zebra',
                `format` VARCHAR(16) NOT NULL,
                `filename` VARCHAR(191) NULL,
                `content_b64` LONGTEXT NOT NULL,
                `status` ENUM('pending','sent','done','failed') NOT NULL DEFAULT 'pending',
                `error` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME NULL,
                `done_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // 21. print_assets - biblioteka gotowych plików do druku (etykiety
            //     produktowe, naklejki). Sam plik leży w storage/uploads (poza
            //     web-rootem, jak załączniki wiadomości) - tu trzymamy tylko wpis.
            "CREATE TABLE IF NOT EXISTS `print_assets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(191) NOT NULL,
                `file_path` VARCHAR(255) NOT NULL,
                `file_name` VARCHAR(191) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}
