<?php
/**
 * 2026_06_02_asset_categories_sort_order.php
 * ------------------------------------------
 * Adds asset_categories.sort_order so the PPE schedule can list asset classes
 * in the entity's statutory order (e.g. Land, Office Equipment, Computers,
 * Motor Vehicles, Furniture) rather than alphabetically.
 *
 * Idempotent: column added only if missing; seeds an initial order from the
 * existing category_id so current ordering is preserved until the user sets it.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: asset_categories.sort_order...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'asset_categories'")->fetch()) {
        echo "  ! asset_categories table not found — skipping.\n";
        exit(0);
    }

    $exists = $pdo->query("SHOW COLUMNS FROM asset_categories LIKE 'sort_order'")->fetch();
    if ($exists) {
        echo "  · column sort_order already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE asset_categories ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
        echo "  + added column sort_order.\n";
        // Seed a stable initial order from category_id (×10 to leave gaps).
        $pdo->exec("UPDATE asset_categories SET sort_order = category_id * 10 WHERE sort_order = 0");
        echo "  + seeded initial sort_order from category_id.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
