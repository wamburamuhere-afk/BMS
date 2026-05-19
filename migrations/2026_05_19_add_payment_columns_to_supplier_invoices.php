<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add payment columns to supplier_invoices...\n";

try {
    $cols = [
        'payment_date'        => 'DATE NULL',
        'payment_method'      => "VARCHAR(50) NULL",
        'payment_ref'         => "VARCHAR(100) NULL",
        'payment_recorded_by' => 'INT NULL',
    ];
    foreach ($cols as $col => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE '{$col}'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE supplier_invoices ADD COLUMN {$col} {$def}");
            echo "Column {$col} added.\n";
        } else {
            echo "Column {$col} already exists, skipping.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
