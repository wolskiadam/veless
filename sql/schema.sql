-- =====================================================================
--  PASE Middleware - schemat bazy danych (MySQL / MariaDB 10.4+)
--  Odpowiednik PostgreSQL z briefu.
--  Uruchom przez phpMyAdmin lub:  mysql -u USER -p DB < sql/schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
--  1. integrations  -- tokeny i sekrety dla każdej platformy
--     (MySQL nie ma ENUM jak Postgres - używamy natywnego MySQL ENUM)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `integrations` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `platform_name`  ENUM('allegro','woocommerce','wfirma') NOT NULL,
    `access_token`   TEXT NULL,
    `refresh_token`  TEXT NULL,
    `expires_at`     DATETIME NULL,            -- kiedy wygasa access_token
    `webhook_secret` VARCHAR(255) NULL,        -- do weryfikacji podpisu webhooka
    `meta`           JSON NULL,                -- dodatkowe dane (np. environment Allegro)
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_platform` (`platform_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. product_mappings  -- mostek SKU <-> ID na każdej platformie
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_mappings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku`              VARCHAR(191) NOT NULL,   -- 191 = bezpieczna długość dla UNIQUE w utf8mb4
    `woo_product_id`   BIGINT UNSIGNED NULL,
    `woo_variant_id`   BIGINT UNSIGNED NULL,
    `allegro_offer_id` VARCHAR(64) NULL,        -- Allegro offer ID bywa stringiem
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sku` (`sku`),
    KEY `idx_allegro_offer` (`allegro_offer_id`),
    KEY `idx_woo_product` (`woo_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. order_logs  -- dziennik zamówień = serce idempotencji
--     UNIQUE(source_platform, source_order_id) blokuje podwójne przetworzenie.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_logs` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `internal_status`   ENUM('received','processing','woo_created','invoiced','completed','failed')
                            NOT NULL DEFAULT 'received',
    `source_platform`   ENUM('allegro','woocommerce','wfirma') NOT NULL,
    `source_order_id`   VARCHAR(128) NOT NULL,
    `woo_order_id`      BIGINT UNSIGNED NULL,
    `wfirma_invoice_id` VARCHAR(128) NULL,
    `last_error`        TEXT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_source_order` (`source_platform`, `source_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. job_queue  -- ZASTĘPUJE Redis/BullMQ na shared hostingu.
--     Webhook tylko wrzuca zadanie tutaj i zwraca 200. Worker (cron)
--     zdejmuje zadania, robi retry z wykładniczym backoffem przez
--     kolumnę `process_after`, a `available_at` opóźnia start (rate-limit).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_queue` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type`       VARCHAR(64) NOT NULL,      -- np. 'allegro.order.new', 'woo.stock.sync'
    `payload`        JSON NOT NULL,             -- dane zadania (oryginalny webhook)
    `dedup_key`      VARCHAR(191) NULL,         -- klucz idempotencji na poziomie kolejki
    `status`         ENUM('pending','reserved','done','failed') NOT NULL DEFAULT 'pending',
    `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `reserved_at`    DATETIME NULL,             -- znacznik claim przez workera (lock)
    `available_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- nie ruszaj przed tym czasem
    `last_error`     TEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_dedup` (`dedup_key`),        -- ten sam webhook nie wejdzie 2x do kolejki
    KEY `idx_claim` (`status`, `available_at`)  -- szybkie pobranie następnego zadania
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Dokumenty sprzedaży wystawione przez integracje księgowe
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_documents` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `woo_order_id`  BIGINT UNSIGNED NOT NULL,
    `integration_id` INT UNSIGNED NOT NULL,
    `provider`      VARCHAR(48) NOT NULL,
    `document_type` VARCHAR(32) NOT NULL,
    `remote_id`     VARCHAR(128) NOT NULL,
    `status`        VARCHAR(32) NOT NULL DEFAULT 'issued',
    `message`       TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_provider_type` (`woo_order_id`, `provider`, `document_type`),
    KEY `idx_remote` (`provider`, `remote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historia nie ma FK do zamówienia: pozostaje po opróżnieniu kosza.
CREATE TABLE IF NOT EXISTS document_issue_operations (
    order_id BIGINT NOT NULL,
    document_type VARCHAR(32) NOT NULL,
    integration_id BIGINT NOT NULL,
    attempt_token VARCHAR(64) NOT NULL,
    state VARCHAR(20) NOT NULL,
    remote_id VARCHAR(128) NULL,
    created_at VARCHAR(30) NOT NULL,
    updated_at VARCHAR(30) NOT NULL,
    PRIMARY KEY (order_id, document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historia nie ma FK do zamówienia: pozostaje po opróżnieniu kosza.
CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    actor_id BIGINT NULL,
    actor_name VARCHAR(190) NOT NULL,
    action VARCHAR(80) NOT NULL,
    before_json TEXT NOT NULL,
    after_json TEXT NOT NULL,
    created_at VARCHAR(30) NOT NULL,
    KEY audit_order_id (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
