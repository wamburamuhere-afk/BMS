<?php
/**
 * 2026_06_02_assets_make.php
 * --------------------------
 * Adds assets.make (manufacturer / brand) so the Fixed Assets Register can show
 * a Make column with real data instead of a permanently-blank field.
 *
 * Idempotent: column added only if missing.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: assets.make...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'assets'")->fetch()) {
        echo "  ! assets table not found — skipping.\n";
        exit(0);
    }

    $exists = $pdo->query("SHOW COLUMNS FROM assets LIKE 'make'")->fetch();
    if ($exists) {
        echo "  · column make already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE assets ADD COLUMN make VARCHAR(100) NULL COMMENT 'Manufacturer / brand' AFTER asset_name");
        echo "  + added column make.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
