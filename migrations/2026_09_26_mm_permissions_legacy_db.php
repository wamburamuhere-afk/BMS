<?php
/**
 * migrations/2026_09_26_mm_permissions_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_09_26_mm_permissions.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'permissions'")->fetch()) {
    echo "Legacy DB has no permissions table — skipping mm_permissions migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_permissions...\n";

try {
    $rows = [
        ['mm_dashboard',       'MM Dashboard',            'View the Mobile Money operations dashboard',                          'Mobile Money'],
        ['mm_agents',          'MM Agents / Outlets',     'View and manage agent outlets (mawakala)',                           'Mobile Money'],
        ['mm_networks',        'MM Networks',             'View and configure mobile money networks and GL accounts',           'Mobile Money'],
        ['mm_transactions',    'MM Transactions',         'Record and view mobile money transactions',                          'Mobile Money'],
        ['mm_float',           'MM Float Management',     'Record float top-ups, withdrawals and opening balances',             'Mobile Money'],
        ['mm_commissions',     'MM Commissions',          'View commission earned and record commissions received from networks','Mobile Money'],
        ['mm_commission_rates','MM Commission Rates',     'View and manage commission tariff schedules per network',            'Mobile Money'],
        ['mm_reconciliation',  'MM Reconciliation',       'Perform daily float and cash reconciliation per till',               'Mobile Money'],
        ['mm_reports',         'MM Reports',              'View Mobile Money reports and analytics',                            'Mobile Money'],
        ['mm_compliance',      'MM Compliance / KYC',     'View KYC records and flagged transactions for BOT compliance',       'Mobile Money'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($rows as $row) {
        $stmt->execute($row);
        echo "  · permission '{$row[0]}' ensured.\n";
    }

    echo "Migration complete: mm_permissions (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
