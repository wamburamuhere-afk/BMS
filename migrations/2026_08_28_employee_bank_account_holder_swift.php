<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.account_holder_name / bank_swift_code...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'account_holder_name'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employees.account_holder_name already exists — skipping.\n";
    } else {
        // Name on the bank account, when it differs from the employee's own name
        // (e.g. a joint account or a name-mismatch with the bank's records).
        $pdo->exec("
            ALTER TABLE employees
            ADD COLUMN account_holder_name VARCHAR(150) NULL
                AFTER bank_name
        ");
        echo "Added employees.account_holder_name.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM employees LIKE 'bank_swift_code'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "employees.bank_swift_code already exists — skipping.\n";
    } else {
        // SWIFT/BIC or local routing code, required by most banks for salary
        // bank-transfer batch files.
        $pdo->exec("
            ALTER TABLE employees
            ADD COLUMN bank_swift_code VARCHAR(30) NULL
                AFTER bank_branch
        ");
        echo "Added employees.bank_swift_code.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
