<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tender participation fee -> real GL payment...\n";

try {
    // Turns the participation fee from a memo field into an actual tracked
    // payment: who it was paid from, when, which expense account it hit, and
    // the outflow transaction id (so it can be reversed on delete, exactly
    // like expenses.transaction_id does for api/account/delete_expense.php).
    $cols = [
        'participation_fee_paid'               => "TINYINT(1) NOT NULL DEFAULT 0",
        'participation_fee_paid_date'          => "DATE NULL DEFAULT NULL",
        'participation_fee_bank_account_id'    => "INT NULL DEFAULT NULL",
        'participation_fee_expense_account_id' => "INT NULL DEFAULT NULL",
        'participation_fee_transaction_id'     => "INT NULL DEFAULT NULL",
    ];

    foreach ($cols as $name => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$name'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE tenders ADD COLUMN `$name` $def");
            echo "  - tenders.$name added.\n";
        } else {
            echo "  - tenders.$name already present, skipped.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
