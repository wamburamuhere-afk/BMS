<?php
/**
 * migrations/tenant/2026_11_02_restaurant_held_sales_shift_nullable.php
 *
 * Restaurant simplicity pass — a waiter can save a table's order WITHOUT a cash
 * register shift (taking an order moves no money; only paying needs a shift).
 *   pos_held_sales.shift_id INT NOT NULL  →  INT NULL
 * Nothing reads shift_id from a held sale (checked), and the column has no
 * foreign key, so existing rows and retail holds are unchanged. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: held sales shift nullable...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'pos_held_sales'")->fetch()) {
        echo "  ~ pos_held_sales table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    $col = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'shift_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  ~ pos_held_sales.shift_id absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    if (strtoupper((string)$col['Null']) === 'NO') {
        $pdo->exec("ALTER TABLE pos_held_sales MODIFY COLUMN `shift_id` INT NULL DEFAULT NULL");
        echo "  + pos_held_sales.shift_id is now nullable.\n";
    } else {
        echo "  ~ pos_held_sales.shift_id already nullable.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
