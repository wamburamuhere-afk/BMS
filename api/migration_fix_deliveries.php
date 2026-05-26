<?php
// File: api/migration_fix_deliveries.php
// scope-audit: skip — one-time migration script; not a runtime data endpoint
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'bms_migrate_2024') die("Unauthorized");

global $pdo;

try {
    echo "Starting Deliveries Table Fix Migration...\n";

    // 1. Ensure do_id exists in deliveries table
    $checkDoId = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'do_id'")->fetch();
    if (!$checkDoId) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN do_id INT NULL AFTER supplier_id");
        echo "Added column: do_id to deliveries table.\n";
    } else {
        echo "Column do_id already exists in deliveries table.\n";
    }

    // 2. Ensure purchase_order_id exists in deliveries table
    $checkPOId = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'purchase_order_id'")->fetch();
    if (!$checkPOId) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN purchase_order_id INT NULL AFTER do_id");
        echo "Added column: purchase_order_id to deliveries table.\n";
    } else {
        echo "Column purchase_order_id already exists in deliveries table.\n";
    }

    // 3. Ensure delivery_attachments table exists (if missing)
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_attachments (
        attachment_id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_type VARCHAR(100),
        file_size INT,
        uploaded_by INT,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (delivery_id)
    )");
    echo "Ensured delivery_attachments table exists.\n";

    echo "\nMigration Successful!\n";

} catch (Exception $e) {
    echo "\nMigration Error: " . $e->getMessage() . "\n";
}
