<?php
/**
 * 2026_06_01_assets_warranty_expiry.php
 * --------------------------------------
 * Asset Register & PPE Schedule — Phase 8 (Intelligence Layer).
 *
 * Adds assets.warranty_expiry so the dashboard can surface a "warranty
 * expiring" alert. Nullable; existing rows unaffected.
 *
 * Idempotent: guarded by SHOW COLUMNS. Re-run safe.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: assets.warranty_expiry...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'assets'")->fetch()) {
        echo "  ! assets table not found — skipping.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM assets LIKE 'warranty_expiry'")->fetch()) {
        echo "  · column warranty_expiry already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE assets ADD COLUMN warranty_expiry DATE NULL COMMENT 'Warranty expiry date (dashboard alert)'");
        echo "  + added column warranty_expiry.\n";
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
