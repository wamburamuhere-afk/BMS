<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payroll partial payment tracking...\n";

try {
    // 1. Add amount_paid column to payroll
    $col = $pdo->query("SHOW COLUMNS FROM payroll LIKE 'amount_paid'")->fetchColumn();
    if (!$col) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER net_salary");
        echo "OK: amount_paid column added to payroll\n";
    } else {
        echo "SKIP: amount_paid already exists\n";
    }

    // 2. Widen payment_status enum to include 'partial'
    $row = $pdo->query("SHOW COLUMNS FROM payroll LIKE 'payment_status'")->fetch(PDO::FETCH_ASSOC);
    if ($row && strpos($row['Type'], "'partial'") === false) {
        $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial') DEFAULT 'pending'");
        echo "OK: 'partial' added to payment_status enum\n";
    } else {
        echo "SKIP: 'partial' already in enum\n";
    }

    // 3. Backfill: existing paid payrolls get amount_paid = net_salary
    $updated = $pdo->exec("UPDATE payroll SET amount_paid = net_salary WHERE payment_status = 'paid' AND amount_paid = 0");
    echo "OK: backfilled amount_paid for {$updated} paid payroll(s)\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
