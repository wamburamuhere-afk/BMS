<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: transactions.transaction_type add 'trip'...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'trip'") !== false) {
        echo "transactions.transaction_type already includes 'trip' — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE transactions MODIFY transaction_type
            ENUM('disbursement','repayment','fee','interest','expense','general','supplier_payment',
                 'received_invoice_payment','sc_payment','payroll','voucher','petty_cash','transfer',
                 'revenue','journal','debit_note_refund','credit_note_refund','petty_cash_topup','trip')
            NOT NULL");
        echo "transactions.transaction_type now includes 'trip'.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
