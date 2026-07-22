<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employee_trips GL integration...\n";

try {
    // ── 1. expense_account_id — the real GL account a trip's cost is booked to.
    // Replaces the old free-text expense_reference for posting purposes (the old
    // column is left in place, untouched, for historical records that used it as
    // a plain note).
    $col = $pdo->query("SHOW COLUMNS FROM employee_trips LIKE 'expense_account_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employee_trips.expense_account_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE employee_trips ADD COLUMN expense_account_id INT NULL AFTER expense_reference");
        echo "Added employee_trips.expense_account_id.\n";
    }

    // ── 2. Payment columns — mirrors expenses' own paid-from/date/amount/transaction_id.
    $col = $pdo->query("SHOW COLUMNS FROM employee_trips LIKE 'paid_from_account_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employee_trips.paid_from_account_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE employee_trips ADD COLUMN paid_from_account_id INT NULL AFTER expense_account_id");
        $pdo->exec("ALTER TABLE employee_trips ADD COLUMN payment_date DATE NULL AFTER paid_from_account_id");
        $pdo->exec("ALTER TABLE employee_trips ADD COLUMN paid_amount DECIMAL(15,2) NULL AFTER payment_date");
        $pdo->exec("ALTER TABLE employee_trips ADD COLUMN transaction_id INT NULL AFTER paid_amount");
        echo "Added employee_trips payment columns (paid_from_account_id, payment_date, paid_amount, transaction_id).\n";
    }

    // ── 3. 'paid' status — a trip can be marked paid once approved (or completed);
    // reversing (cancel/delete) at any later point unwinds both the settlement and
    // the accrual, whichever were posted.
    $statusCol = $pdo->query("SHOW COLUMNS FROM employee_trips LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'paid'") !== false) {
        echo "employee_trips.status already includes 'paid' — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE employee_trips MODIFY status
            ENUM('pending','approved','rejected','completed','paid','cancelled','deleted')
            NOT NULL DEFAULT 'pending'");
        echo "employee_trips.status now includes 'paid'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
