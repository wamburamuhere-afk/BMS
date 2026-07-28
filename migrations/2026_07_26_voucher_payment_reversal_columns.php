<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: voucher_payments reversal columns...\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM voucher_payments")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('bank_transaction_id', $cols, true)) {
        $pdo->exec("ALTER TABLE voucher_payments ADD COLUMN bank_transaction_id INT NULL AFTER gl_transaction_id");
        echo "Added voucher_payments.bank_transaction_id.\n";
    } else {
        echo "voucher_payments.bank_transaction_id already exists — skipping.\n";
    }

    if (!in_array('reversed_at', $cols, true)) {
        $pdo->exec("ALTER TABLE voucher_payments ADD COLUMN reversed_at DATETIME NULL AFTER created_at");
        echo "Added voucher_payments.reversed_at.\n";
    } else {
        echo "voucher_payments.reversed_at already exists — skipping.\n";
    }

    if (!in_array('reversed_by', $cols, true)) {
        $pdo->exec("ALTER TABLE voucher_payments ADD COLUMN reversed_by INT NULL AFTER reversed_at");
        echo "Added voucher_payments.reversed_by.\n";
    } else {
        echo "voucher_payments.reversed_by already exists — skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
