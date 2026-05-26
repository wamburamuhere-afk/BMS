<?php
// File: api/migrate_po_workflow.php
// scope-audit: skip — one-time migration script; not a runtime data endpoint
require_once __DIR__ . '/../roots.php';

// Security check: only allow if logged in as admin OR if a secret token is provided
$token = $_GET['token'] ?? '';
$secret = 'bms_migrate_2024';

if (!isAuthenticated() && $token !== $secret) {
    http_response_code(401);
    die("Unauthorized — Admin only.");
}

global $pdo;

try {
    echo "Starting PO Workflow Migration...<br>";

    // 1. Add snapshot columns to purchase_orders table
    $columns = [
        'prepared_by_name' => "VARCHAR(150) NULL AFTER status",
        'prepared_by_role' => "VARCHAR(100) NULL AFTER prepared_by_name",
        'reviewed_by_name' => "VARCHAR(150) NULL AFTER prepared_by_role",
        'reviewed_by_role' => "VARCHAR(100) NULL AFTER reviewed_by_name",
        'reviewed_at'      => "DATETIME NULL AFTER reviewed_by_role",
        'approved_by_name' => "VARCHAR(150) NULL AFTER reviewed_at",
        'approved_by_role' => "VARCHAR(100) NULL AFTER approved_by_name",
        'approved_at'      => "DATETIME NULL AFTER approved_by_role"
    ];

    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE '$col'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN $col $definition");
            echo "Added column: $col<br>";
        } else {
            echo "Column already exists: $col<br>";
        }
    }

    // 2. Ensure 'review' is a valid status in the ENUM (if it's an ENUM) or just allow it
    // Most tables in this DB use VARCHAR for status, let's check purchase_orders
    $checkStatus = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (strpos($checkStatus['Type'], 'enum') !== false) {
        // It's an enum, we must expand it
        $newEnum = "ENUM('draft','pending','review','approved','ordered','partially_received','received','cancelled','completed')";
        $pdo->exec("ALTER TABLE purchase_orders MODIFY COLUMN status $newEnum DEFAULT 'pending'");
        echo "Updated status ENUM to include 'review' and 'approved'<br>";
    }

    echo "<b>PO Migration Successful!</b>";

} catch (Exception $e) {
    echo "<b>Error!</b><br>" . $e->getMessage();
}
