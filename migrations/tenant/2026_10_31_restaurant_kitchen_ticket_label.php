<?php
/**
 * migrations/tenant/2026_10_31_restaurant_kitchen_ticket_label.php
 *
 * restaurant_plan.md Phase 3 — take-away / delivery orders reach the kitchen too.
 *   kitchen_tickets.order_label VARCHAR(80) NULL — what the cook sees ("Table T3", "Take away · RCP-…")
 *   kitchen_tickets.sale_id     INT NULL         — the paid sale a take-away/delivery ticket came from
 * Both nullable: existing tickets unchanged. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: kitchen ticket label...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'kitchen_tickets'")->fetch()) {
        echo "  ~ kitchen_tickets table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    if (!$pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'order_label'")->fetch()) {
        $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `order_label` VARCHAR(80) NULL DEFAULT NULL");
        echo "  + kitchen_tickets.order_label added.\n";
    }
    if (!$pdo->query("SHOW COLUMNS FROM kitchen_tickets LIKE 'sale_id'")->fetch()) {
        $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `sale_id` INT NULL DEFAULT NULL, ADD INDEX idx_kt_sale (sale_id)");
        echo "  + kitchen_tickets.sale_id added.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
