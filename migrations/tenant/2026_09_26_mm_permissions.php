<?php
/**
 * migrations/tenant/2026_09_26_mm_permissions.php
 *
 * Phase 0 — Mobile Money module: permission page_key rows.
 * Idempotent — INSERT IGNORE throughout.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mm_permissions...\n";

try {
    $rows = [
        ['mm_dashboard',      'MM Dashboard',            'View the Mobile Money operations dashboard',                          'Mobile Money'],
        ['mm_agents',         'MM Agents / Outlets',     'View and manage agent outlets (mawakala)',                           'Mobile Money'],
        ['mm_networks',       'MM Networks',             'View and configure mobile money networks and GL accounts',           'Mobile Money'],
        ['mm_transactions',   'MM Transactions',         'Record and view mobile money transactions',                          'Mobile Money'],
        ['mm_float',          'MM Float Management',     'Record float top-ups, withdrawals and opening balances',             'Mobile Money'],
        ['mm_commissions',    'MM Commissions',          'View commission earned and record commissions received from networks','Mobile Money'],
        ['mm_commission_rates','MM Commission Rates',    'View and manage commission tariff schedules per network',            'Mobile Money'],
        ['mm_reconciliation', 'MM Reconciliation',       'Perform daily float and cash reconciliation per till',               'Mobile Money'],
        ['mm_reports',        'MM Reports',              'View Mobile Money reports and analytics',                            'Mobile Money'],
        ['mm_compliance',     'MM Compliance / KYC',     'View KYC records and flagged transactions for BOT compliance',       'Mobile Money'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($rows as $row) {
        $stmt->execute($row);
        echo "  · permission '{$row[0]}' ensured.\n";
    }

    echo "Migration complete: mm_permissions.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
