<?php
require_once 'roots.php';

try {
    // 1. Update Budgets table
    $pdo->exec("ALTER TABLE budgets MODIFY COLUMN status ENUM('draft','pending','approved','rejected','paid') DEFAULT 'draft'");
    
    // Add line_items column if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'line_items'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE budgets ADD COLUMN line_items JSON DEFAULT NULL AFTER notes");
    }

    // Add payment info to budgets
    $cols = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'payment_reference'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE budgets ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL AFTER line_items");
    }
    
    $cols = $pdo->query("SHOW COLUMNS FROM budgets LIKE 'attachment'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE budgets ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER payment_reference");
    }

    // 2. Update Payment Vouchers table
    $cols = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'attachment'")->fetch();
    if (!$cols) {
        $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER reference_number");
    }

    echo "Migration completed successfully!";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
