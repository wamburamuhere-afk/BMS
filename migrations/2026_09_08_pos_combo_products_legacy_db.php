<?php
/**
 * migrations/2026_09_08_pos_combo_products_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_08_pos_combo_products.php (Phase 23 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists, and
 * 2026_09_08_pos_network_printer_legacy_db.php for why the anchor-missing
 * degrade-instead-of-fail approach is used here too.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS combo products on the legacy database (Phase 23)...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_combo'")->fetch();
    if ($exists) {
        echo "  · products.is_combo already present.\n";
    } else {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_service'")->fetch();
        $position = $anchor ? " AFTER is_service" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN is_combo TINYINT(1) NOT NULL DEFAULT 0$position");
        echo "  + products.is_combo added" . ($position ? "" : " (anchor column 'is_service' not found — appended at end instead)") . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
