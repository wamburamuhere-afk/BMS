<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    $sqls = [
        "ALTER TABLE tenders ADD COLUMN currency VARCHAR(10) DEFAULT 'TShs' AFTER status",
        "ALTER TABLE tenders ADD COLUMN participation_fee_required VARCHAR(10) DEFAULT 'No' AFTER currency",
        "ALTER TABLE tenders ADD COLUMN participation_fee_amount DECIMAL(20,2) DEFAULT 0 AFTER participation_fee_required"
    ];

    foreach ($sqls as $sql) {
        $pdo->exec($sql);
        echo "Executed: $sql\n";
    }
    echo "Database updated successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
