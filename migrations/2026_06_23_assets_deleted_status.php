<?php
/**
 * 2026_06_23_assets_deleted_status.php
 * ------------------------------------
 * Adds a 'deleted' value to assets.status so an asset can be SOFT-deleted (§12)
 * instead of hard-deleted. The whole asset module already filters
 * `status != 'deleted'` (get_assets, the PPE schedule, depreciation run, asset
 * view…), but the enum never actually allowed the value — because delete_asset.php
 * was a bare `DELETE FROM assets`. The delete fix (account_financial.md #13)
 * switches to soft-delete + capitalisation reversal, which needs this value.
 *
 * Idempotent: only alters the enum when 'deleted' is missing. (DDL — no transaction.)
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: assets 'deleted' status...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'assets'")->fetch()) {
        echo "  ! assets table not found — skipping.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM assets LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'deleted'") !== false) {
        echo "  · status enum already includes 'deleted', skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE assets
              MODIFY COLUMN status ENUM('active','maintenance','disposed','written_off','deleted')
              NOT NULL DEFAULT 'active'
        ");
        echo "  + status enum now includes 'deleted'.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
