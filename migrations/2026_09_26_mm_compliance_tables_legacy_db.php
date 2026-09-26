<?php
/**
 * migrations/2026_09_26_mm_compliance_tables_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_09_26_mm_compliance_tables.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    echo "Legacy DB has no users table — skipping mm_compliance_tables migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_compliance_tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_kyc_records` (
            `kyc_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `mm_txn_id`         BIGINT UNSIGNED NOT NULL,
            `customer_phone`    VARCHAR(20)  NOT NULL DEFAULT '',
            `customer_name`     VARCHAR(120) NOT NULL DEFAULT '',
            `id_type`           ENUM('nida','voters','passport','driving_licence') NOT NULL,
            `id_number`         VARCHAR(40)  NOT NULL DEFAULT '',
            `id_photo_path`     VARCHAR(255) NULL DEFAULT NULL,
            `captured_by`       INT UNSIGNED NOT NULL,
            `captured_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`kyc_id`),
            UNIQUE KEY `ux_mm_kyc_txn` (`mm_txn_id`),
            KEY `idx_mm_kyc_phone` (`customer_phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_kyc_records created.\n";

    echo "Migration complete: mm_compliance_tables (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
