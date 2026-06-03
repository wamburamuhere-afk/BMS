<?php
/**
 * 2026_06_03_subcontractor_default_wht.php
 * ----------------------------------------
 * Adds sub_contractors.default_wht_rate_id — the sub-contractor's default WHT
 * category, used to auto-fill the WHT dropdown on the Record-Payment screen for
 * their invoices (sub-contractor invoices already flow through the
 * supplier_invoices WHT path, so capture/posting already works). Mirror of
 * suppliers.default_wht_rate_id. Purely additive, idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: sub_contractors.default_wht_rate_id...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'sub_contractors'")->fetch()) {
        echo "  ! sub_contractors not found — skipping.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM sub_contractors LIKE 'default_wht_rate_id'")->fetch()) {
        echo "  · sub_contractors.default_wht_rate_id already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE sub_contractors ADD COLUMN default_wht_rate_id INT NULL DEFAULT NULL");
        echo "  + added sub_contractors.default_wht_rate_id.\n";
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
