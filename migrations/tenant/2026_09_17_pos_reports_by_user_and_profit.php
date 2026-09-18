<?php
/**
 * migrations/tenant/2026_09_17_pos_reports_by_user_and_profit.php
 *
 * Two new Reports-module permissions for Simple POS shops:
 *   - pos_user_sales_report — per-cashier sales totals + items sold
 *     (app/constant/reports/pos_user_sales_report.php)
 *   - pos_profit_report — gross/net profit for a chosen date range, sourced
 *     from glProfitLoss() (app/constant/reports/pos_profit_report.php)
 *
 * Brand-new permissions, not a carve-out of an existing grant — there is no
 * natural "everyone who already has X should get this too" source, and
 * profit/margin data is sensitive, so (mirroring
 * migrations/2026_09_07_pos_advanced_permission.php) this seeds the
 * permissions rows only. They stay admin-only (isAdmin() bypasses canView())
 * until a tenant owner explicitly grants them to another role via
 * Roles & Permissions.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: seed POS Simple-mode report permissions...\n";

try {
    $toSeed = [
        ['pos_user_sales_report', 'Sales by User Report', 'Per-cashier POS sales totals and items sold, for Simple POS shops'],
        ['pos_profit_report', 'Profit Report', 'Gross/net profit for a chosen date range, for Simple POS shops'],
    ];

    foreach ($toSeed as [$pageKey, $pageName, $description]) {
        $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ?");
        $exists->execute([$pageKey]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare("
                INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                VALUES ('', ?, ?, ?, 'Reports', 0, NOW())
            ")->execute([$pageKey, $pageName, $description]);
            echo "  + permission '{$pageKey}' seeded.\n";
        } else {
            echo "  · permission '{$pageKey}' already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
