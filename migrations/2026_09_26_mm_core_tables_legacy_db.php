<?php
/**
 * migrations/2026_09_26_mm_core_tables_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_09_26_mm_core_tables.php.
 * Skips silently on a tenant-only install where the legacy DB has no 'users' table.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    echo "Legacy DB has no users table — skipping mm_core_tables migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_core_tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_networks` (
            `network_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_code`          VARCHAR(20)  NOT NULL,
            `network_name`          VARCHAR(80)  NOT NULL,
            `provider`              VARCHAR(80)  NOT NULL DEFAULT '',
            `short_code`            VARCHAR(20)  NOT NULL DEFAULT '',
            `color_hex`             VARCHAR(7)   NOT NULL DEFAULT '#0d6efd',
            `float_account_id`      INT UNSIGNED NULL DEFAULT NULL,
            `commission_account_id` INT UNSIGNED NULL DEFAULT NULL,
            `sort_order`            INT NOT NULL DEFAULT 0,
            `status`                ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`            INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`network_id`),
            UNIQUE KEY `ux_mm_networks_code` (`network_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_networks created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_agents` (
            `agent_id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `agent_code`            VARCHAR(40)  NOT NULL,
            `agent_name`            VARCHAR(120) NOT NULL,
            `outlet_type`           ENUM('main','sub','kiosk','shop_in_shop') NOT NULL DEFAULT 'main',
            `parent_agent_id`       INT UNSIGNED NULL DEFAULT NULL,
            `warehouse_id`          INT UNSIGNED NULL DEFAULT NULL,
            `bot_license`           VARCHAR(80)  NULL DEFAULT NULL,
            `region`                VARCHAR(80)  NULL DEFAULT NULL,
            `district`              VARCHAR(80)  NULL DEFAULT NULL,
            `ward`                  VARCHAR(80)  NULL DEFAULT NULL,
            `street`                VARCHAR(120) NULL DEFAULT NULL,
            `gps_lat`               DECIMAL(10,7) NULL DEFAULT NULL,
            `gps_lng`               DECIMAL(10,7) NULL DEFAULT NULL,
            `manager_user_id`       INT UNSIGNED NULL DEFAULT NULL,
            `phone_primary`         VARCHAR(20)  NOT NULL DEFAULT '',
            `phone_alt`             VARCHAR(20)  NULL DEFAULT NULL,
            `opening_date`          DATE NULL DEFAULT NULL,
            `low_float_alert_pct`   TINYINT UNSIGNED NOT NULL DEFAULT 20,
            `status`                ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
            `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_by`            INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`agent_id`),
            UNIQUE KEY `ux_mm_agents_code` (`agent_code`),
            KEY `idx_mm_agents_status` (`status`),
            KEY `idx_mm_agents_parent` (`parent_agent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_agents created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_tills` (
            `till_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `agent_id`      INT UNSIGNED NOT NULL,
            `network_id`    INT UNSIGNED NOT NULL,
            `till_number`   VARCHAR(40)  NOT NULL,
            `sim_msisdn`    VARCHAR(20)  NOT NULL DEFAULT '',
            `float_ceiling` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `cash_ceiling`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `status`        ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `created_by`    INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`till_id`),
            UNIQUE KEY `ux_mm_tills_agent_network_till` (`agent_id`,`network_id`,`till_number`),
            KEY `idx_mm_tills_agent` (`agent_id`),
            KEY `idx_mm_tills_network` (`network_id`),
            KEY `idx_mm_tills_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_tills created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_commission_rates` (
            `rate_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_id`     INT UNSIGNED NOT NULL,
            `txn_type`       ENUM('cash_in','cash_out','send','bill_pay','airtime','bank_to_wallet','wallet_to_bank','international') NOT NULL,
            `amount_from`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `amount_to`      DECIMAL(15,2) NOT NULL DEFAULT 9999999999.99,
            `rate_type`      ENUM('flat','percent') NOT NULL DEFAULT 'flat',
            `rate_value`     DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            `min_commission` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `max_commission` DECIMAL(12,2) NULL DEFAULT NULL,
            `effective_from` DATE NOT NULL,
            `effective_to`   DATE NULL DEFAULT NULL,
            `status`         ENUM('active','superseded') NOT NULL DEFAULT 'active',
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`     INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`rate_id`),
            KEY `idx_mm_rates_lookup` (`network_id`,`txn_type`,`amount_from`,`effective_from`),
            KEY `idx_mm_rates_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_commission_rates created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_transactions` (
            `mm_txn_id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `txn_code`          VARCHAR(40)  NOT NULL,
            `till_id`           INT UNSIGNED NOT NULL,
            `network_id`        INT UNSIGNED NOT NULL,
            `agent_id`          INT UNSIGNED NOT NULL,
            `txn_type`          ENUM('cash_in','cash_out','send','bill_pay','airtime','bank_to_wallet','wallet_to_bank','international') NOT NULL,
            `txn_date`          DATE NOT NULL,
            `txn_time`          TIME NOT NULL,
            `customer_phone`    VARCHAR(20)  NOT NULL DEFAULT '',
            `customer_name`     VARCHAR(80)  NULL DEFAULT NULL,
            `counterparty_phone` VARCHAR(20) NULL DEFAULT NULL,
            `reference_no`      VARCHAR(60)  NOT NULL DEFAULT '',
            `principal_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `customer_fee`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `commission_earned` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `cash_effect`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `float_effect`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `teller_user_id`    INT UNSIGNED NOT NULL,
            `shift_id`          INT UNSIGNED NULL DEFAULT NULL,
            `kyc_required`      TINYINT(1) NOT NULL DEFAULT 0,
            `kyc_document_id`   INT UNSIGNED NULL DEFAULT NULL,
            `suspicious_flag`   TINYINT(1) NOT NULL DEFAULT 0,
            `notes`             TEXT NULL DEFAULT NULL,
            `journal_entry_id`  INT UNSIGNED NULL DEFAULT NULL,
            `status`            ENUM('recorded','posted','void','reversed') NOT NULL DEFAULT 'recorded',
            `void_reason`       TEXT NULL DEFAULT NULL,
            `voided_by`         INT UNSIGNED NULL DEFAULT NULL,
            `voided_at`         DATETIME NULL DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`        INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`mm_txn_id`),
            UNIQUE KEY `ux_mm_transactions_code` (`txn_code`),
            KEY `idx_mm_txn_date` (`txn_date`),
            KEY `idx_mm_txn_till_date` (`till_id`,`txn_date`),
            KEY `idx_mm_txn_agent_date` (`agent_id`,`txn_date`),
            KEY `idx_mm_txn_network_type_date` (`network_id`,`txn_type`,`txn_date`),
            KEY `idx_mm_txn_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_transactions created.\n";

    echo "Migration complete: mm_core_tables (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
