<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * 2026_07_30_remove_asset_maintenance.php
 * -------------------------------------------
 * post_principle.md gap fix — Maintenance. Two parallel systems existed for
 * the same real-world event: asset_maintenance (zero GL posting of any kind,
 * despite a real "cost" field) and maintenance_logs (posts correctly). This
 * removes the broken one now that everything reachable in the UI — the main
 * Maintenance page and Asset View's quick "Log Maintenance" modal — writes
 * to maintenance_logs only (see api/operations/save_maintenance_log.php,
 * app/bms/operations/{maintenance,asset_view,asset_dashboard}.php).
 *
 * Confirmed before writing this: 0 live rows in asset_maintenance on this
 * database — nothing to migrate. The table's one real feature
 * (next_due_date, used by asset_dashboard.php's overdue-maintenance widget)
 * was already added to maintenance_logs in the sibling migration
 * 2026_07_30_maintenance_logs_payment.php.
 *
 * The original creation migration (2026_06_01_asset_ppe_tables.php) is left
 * untouched — deployed migration history is a one-way log; this migration
 * is the record of the removal, not a rewrite of the past.
 *
 * Idempotent: checks existence first.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: remove asset_maintenance...\n";

try {
    if ($pdo->query("SHOW TABLES LIKE 'asset_maintenance'")->fetch()) {
        $rows = (int)$pdo->query("SELECT COUNT(*) FROM asset_maintenance")->fetchColumn();
        if ($rows > 0) {
            echo "  ! asset_maintenance has $rows row(s) — NOT dropping automatically.\n";
            echo "  ! Review and migrate this data into maintenance_logs by hand first, then re-run.\n";
            echo "Migration complete (table left in place).\n";
            exit(0);
        }
        $pdo->exec("DROP TABLE asset_maintenance");
        echo "  - dropped table asset_maintenance (was empty).\n";
    } else {
        echo "  = table asset_maintenance already absent — skipping.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
