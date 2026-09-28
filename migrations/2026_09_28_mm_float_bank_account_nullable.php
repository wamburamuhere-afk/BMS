<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: make mm_float_movements.bank_account_id nullable...\n";

try {
    // Check current column definition
    $col = $pdo->query("SHOW COLUMNS FROM mm_float_movements LIKE 'bank_account_id'")->fetch(PDO::FETCH_ASSOC);

    if (!$col) {
        echo "  Column bank_account_id not found — skipping.\n";
    } elseif (stripos($col['Null'], 'YES') !== false) {
        echo "  Column already nullable — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE mm_float_movements MODIFY COLUMN bank_account_id INT UNSIGNED NULL DEFAULT NULL");
        echo "  + bank_account_id altered to NULL DEFAULT NULL.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
