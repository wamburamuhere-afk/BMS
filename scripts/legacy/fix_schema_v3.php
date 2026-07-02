<?php
// fix_schema_v3.php
require_once 'roots.php';
global $pdo;

try {
    echo "Checking 'transactions' table for missing columns...\n";
    $stmt = $pdo->query("DESCRIBE transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = [];
    $required = ['account_id', 'contra_account_id', 'disbursement_account_id', 'loan_id'];
    
    foreach ($required as $col) {
        if (!in_array($col, $columns)) {
            $missing[] = $col;
        }
    }

    if (!empty($missing)) {
        echo "Missing columns: " . implode(', ', $missing) . "\nAdding them now...\n";
        foreach ($missing as $col) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN `$col` INT NULL DEFAULT 0");
            echo "Added $col.\n";
        }
    } else {
        echo "All core columns present.\n";
    }

    echo "\nEnsuring 'books_transactions' has 'description' column...\n";
    $stmt2 = $pdo->query("DESCRIBE books_transactions");
    $columns2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('description', $columns2)) {
        $pdo->exec("ALTER TABLE books_transactions ADD COLUMN `description` TEXT NULL");
        echo "Added 'description' to 'books_transactions'.\n";
    } else {
        echo "'description' column already exists in 'books_transactions'.\n";
    }

    echo "\nDatabase fix complete.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
