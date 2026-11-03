<?php
/**
 * migrations/2026_11_02_restaurant_held_sales_shift_nullable_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_02_restaurant_held_sales_shift_nullable.php onto
 * the LEGACY / non-tenant database. Makes pos_held_sales.shift_id nullable so a
 * waiter can save a table order without a cash shift. Idempotent; degrades if absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: held sales shift nullable (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'pos_held_sales'")->fetch()) {
        echo "  · pos_held_sales does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    $col = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'shift_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  · pos_held_sales.shift_id does not exist — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    if (strtoupper((string)$col['Null']) === 'NO') {
        $pdo->exec("ALTER TABLE pos_held_sales MODIFY COLUMN `shift_id` INT NULL DEFAULT NULL");
        echo "  + pos_held_sales.shift_id is now nullable.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
