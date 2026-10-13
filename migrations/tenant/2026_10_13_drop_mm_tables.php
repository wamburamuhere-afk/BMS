<?php
/**
 * migrations/tenant/2026_10_13_drop_mm_tables.php
 *
 * Remove all Mobile Money module tables from each tenant DB.
 * Mobile Money moved to the LMS product; no real data existed in BMS.
 * Drop order respects FK dependencies (child tables first).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: drop_mm_tables...\n";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'mm_kyc_records',
        'mm_recon_items',
        'mm_reconciliations',
        'mm_float_snapshots',
        'mm_commissions_received',
        'mm_float_movements',
        'mm_shifts',
        'mm_user_agent_grants',
        'mm_transactions',
        'mm_commission_rates',
        'mm_tills',
        'mm_agents',
        'mm_networks',
    ];

    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        echo "  - $table dropped.\n";
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "Migration complete: drop_mm_tables.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
