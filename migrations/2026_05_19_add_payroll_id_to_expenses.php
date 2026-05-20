<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add payroll_id to expenses...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'payroll_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN payroll_id INT NULL DEFAULT NULL AFTER invoice_id");
        echo "Column payroll_id added to expenses.\n";
    } else {
        echo "Column payroll_id already exists — skipping.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
