<?php
/**
 * 2026_05_29_grn_delivery_id.php
 * --------------------------------
 * Links a GRN (purchase_receipts) to the inbound Delivery Note
 * (deliveries) that was the source of the receipt.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: purchase_receipts.delivery_id...\n";

try {
    if (!$pdo->query("SHOW COLUMNS FROM `purchase_receipts` LIKE 'delivery_id'")->fetch()) {
        $pdo->exec("ALTER TABLE `purchase_receipts` ADD COLUMN `delivery_id` INT NULL DEFAULT NULL AFTER `delivery_note`");
        echo "  + added purchase_receipts.delivery_id\n";
    } else {
        echo "  · purchase_receipts.delivery_id already exists, skipping.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
