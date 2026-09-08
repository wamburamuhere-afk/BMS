<?php
/**
 * migrations/tenant/2026_09_08_pos_cash_denominations.php
 *
 * Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting at shift
 * open/close. The existing single opening_cash/ending_cash totals stay
 * authoritative (unchanged) — a denomination breakdown is supporting detail,
 * not a second source of truth.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS cash denominations (Phase 20)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `cash_denomination_counts` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `shift_id` INT NOT NULL,
            `context` ENUM('open','close') NOT NULL,
            `denomination_value` DECIMAL(12,2) NOT NULL,
            `count` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_shift_context_denom` (`shift_id`, `context`, `denomination_value`),
            KEY `idx_shift_id` (`shift_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table cash_denomination_counts ready.\n";

    // Configurable denomination list (settings page, Phase 20 UI) — not
    // hardcoded. Default = current TZS note/coin values.
    $exists = $pdo->prepare("SELECT 1 FROM system_settings WHERE setting_key = ?");
    $exists->execute(['tzs_denominations']);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('tzs_denominations', '10000,5000,2000,1000,500,200,100,50', 'pos')")->execute();
        echo "  + setting 'tzs_denominations' seeded with default TZS note/coin values.\n";
    } else {
        echo "  · setting 'tzs_denominations' already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
