<?php
/**
 * 2026_06_01_asset_categories_deleted_status.php
 * ----------------------------------------------
 * Adds a 'deleted' value to asset_categories.status so categories can be
 * soft-deleted (§12) from the Asset Categories admin page instead of being
 * hard-deleted (which the FK from assets.category_id would block anyway).
 *
 * Idempotent: re-run safe — only alters the enum if 'deleted' is missing.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_categories 'deleted' status...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'asset_categories'")->fetch()) {
        echo "  ! asset_categories table not found — skipping.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM asset_categories LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'deleted'") !== false) {
        echo "  · status enum already includes 'deleted', skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE asset_categories
              MODIFY COLUMN status ENUM('active','archived','deleted')
              NOT NULL DEFAULT 'active'
        ");
        echo "  + status enum now includes 'deleted'.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
