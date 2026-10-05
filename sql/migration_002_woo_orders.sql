-- =====================================================================
--  Migracja 002: tabela woo_orders
--  Przechowuje zamówienia pobrane z WooCommerce (webhook order.created/updated).
--  Na teraz: tylko import i podgląd w panelu. Automatyzacje (faktury itp.)
--  podłączymy później, korzystając z zapisanego payloadu.
--
--  Import: phpMyAdmin lub  mysql -u USER -p DB < sql/migration_002_woo_orders.sql
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `woo_orders` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `woo_order_id`   BIGINT UNSIGNED NOT NULL,            -- ID zamówienia w WooCommerce
    `order_number`   VARCHAR(64) NULL,                    -- numer widoczny dla klienta
    `status`         VARCHAR(32) NULL,                    -- woo status: processing, completed, ...
    `currency`       VARCHAR(8)  NULL,
    `total`          DECIMAL(12,2) NULL,
    `customer_name`  VARCHAR(255) NULL,
    `customer_email` VARCHAR(255) NULL,
    `date_created`   DATETIME NULL,                       -- data zamówienia wg Woo
    `payload`        JSON NOT NULL,                       -- pełne zamówienie (do późniejszych automatyzacji)
    `imported_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_woo_order` (`woo_order_id`),           -- idempotencja: jedno zamówienie = jeden wiersz
    KEY `idx_status` (`status`),
    KEY `idx_date_created` (`date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
