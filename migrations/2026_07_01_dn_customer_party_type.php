<?php
/**
 * 2026_07_01_dn_customer_party_type.php
 * ---------------------------------------
 * Widens deliveries.party_type to support 'customer' for outbound Delivery
 * Notes created against a Customer LPO (see 2026_07_01_lpo_standalone_foundation.php
 * for the customer_lpo_id link column). Ordinary supplier/subcontractor DNs
 * are unaffected — this is purely additive to the ENUM.
 *
 * deliveries.customer_id already exists (added in an earlier schema version
 * but never written to by any code path) — this migration starts using it
 * for LPO-linked outbound DNs instead of adding a new column.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: DN customer party_type...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'party_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  deliveries.party_type column not found — skipping.\n";
    } elseif (strpos($col['Type'], "'customer'") !== false) {
        echo "  · party_type ENUM already includes 'customer' — skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE deliveries
            MODIFY COLUMN party_type ENUM('supplier','subcontractor','customer') NOT NULL DEFAULT 'supplier'
        ");
        echo "  + party_type ENUM widened to include 'customer'.\n";
    }

    $hasCustomerId = (bool)$pdo->query("SHOW COLUMNS FROM deliveries LIKE 'customer_id'")->fetch();
    if (!$hasCustomerId) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN customer_id INT NULL AFTER subcontractor_id");
        $pdo->exec("ALTER TABLE deliveries ADD INDEX idx_del_customer_id (customer_id)");
        echo "  + deliveries.customer_id added (with index).\n";
    } else {
        echo "  · deliveries.customer_id already exists (idx_customer_id) — reusing, no new column/index needed.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
