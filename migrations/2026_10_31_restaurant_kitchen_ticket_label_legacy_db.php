<?php
/**
 * migrations/2026_10_31_restaurant_kitchen_ticket_label_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_10_31_restaurant_kitchen_ticket_label.php onto
 * the LEGACY / non-tenant database. Adds nullable kitchen_tickets.order_label
 * and kitchen_tickets.sale_id. Idempotent; degrades if the table is absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: kitchen ticket label (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'kitchen_tickets'")->fetch()) {
        echo "  · kitchen_tickets does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    if (!$pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'order_label'")->fetch()) {
        $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `order_label` VARCHAR(80) NULL DEFAULT NULL");
        echo "  + Added kitchen_tickets.order_label.\n";
    }
    if (!$pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'sale_id'")->fetch()) {
        $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `sale_id` INT NULL DEFAULT NULL, ADD INDEX idx_kt_sale (sale_id)");
        echo "  + Added kitchen_tickets.sale_id.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
