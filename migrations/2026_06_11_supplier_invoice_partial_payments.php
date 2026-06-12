<?php
require_once __DIR__ . '/../roots.php';

// 1. Create supplier_invoice_payments table
$exists = $pdo->query("SHOW TABLES LIKE 'supplier_invoice_payments'")->fetchColumn();
if (!$exists) {
    $pdo->exec("
        CREATE TABLE supplier_invoice_payments (
            id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_id         INT NOT NULL,
            payment_date       DATE NOT NULL,
            amount             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_method     VARCHAR(50) NOT NULL,
            payment_account_id INT NULL,
            reference          VARCHAR(100) NULL,
            wht_rate_id        INT NULL,
            wht_base           DECIMAL(15,2) NULL,
            wht_amount         DECIMAL(15,2) NULL,
            journal_txn_id     INT NULL,
            recorded_by        INT NOT NULL,
            created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_invoice_id (invoice_id),
            INDEX idx_payment_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK: supplier_invoice_payments table created\n";
} else {
    echo "SKIP: supplier_invoice_payments already exists\n";
}

// 2. Add amount_paid column to supplier_invoices
$col = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'amount_paid'")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE supplier_invoices ADD COLUMN amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER amount");
    echo "OK: amount_paid column added to supplier_invoices\n";
} else {
    echo "SKIP: amount_paid already exists\n";
}

// 3. Add 'partial' to status enum
$row = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
if ($row && strpos($row['Type'], 'partial') === false) {
    $pdo->exec("ALTER TABLE supplier_invoices MODIFY COLUMN status ENUM('pending','reviewed','approved','partial','paid','deleted') NOT NULL DEFAULT 'pending'");
    echo "OK: 'partial' added to supplier_invoices.status enum\n";
} else {
    echo "SKIP: 'partial' already in enum\n";
}

// 4. Backfill: existing paid invoices get amount_paid = amount
$updated = $pdo->exec("UPDATE supplier_invoices SET amount_paid = amount WHERE status = 'paid' AND amount_paid = 0");
echo "OK: backfilled amount_paid for {$updated} existing paid invoice(s)\n";
