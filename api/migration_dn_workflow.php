<?php
// File: api/migration_dn_workflow.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'bms_migrate_2024') die("Unauthorized");

global $pdo;

try {
    echo "Starting Delivery Note Workflow Migration...\n";

    // 1. Update status ENUM to include 'review'
    echo "Updating status ENUM in deliveries table...\n";
    $pdo->exec("ALTER TABLE deliveries MODIFY COLUMN status ENUM('draft', 'review', 'approved', 'dispatched', 'delivered', 'cancelled') DEFAULT 'draft'");

    // 2. Add snapshot columns for authorization trail
    $columns = [
        'prepared_by_name' => "VARCHAR(150) NULL AFTER updated_by",
        'prepared_by_role' => "VARCHAR(100) NULL AFTER prepared_by_name",
        'prepared_at'      => "DATETIME NULL AFTER prepared_by_role",
        'reviewed_by_name' => "VARCHAR(150) NULL AFTER prepared_at",
        'reviewed_by_role' => "VARCHAR(100) NULL AFTER reviewed_by_name",
        'reviewed_at'      => "DATETIME NULL AFTER reviewed_by_role",
        'approved_by_name' => "VARCHAR(150) NULL AFTER reviewed_at",
        'approved_by_role' => "VARCHAR(100) NULL AFTER approved_by_name",
        'approved_at'      => "DATETIME NULL AFTER approved_by_role"
    ];

    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM deliveries LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE deliveries ADD COLUMN $col $definition");
            echo "Added column: $col\n";
        } else {
            echo "Column $col already exists.\n";
        }
    }

    echo "\nMigration Successful!\n";

} catch (Exception $e) {
    echo "\nMigration Error: " . $e->getMessage() . "\n";
}
