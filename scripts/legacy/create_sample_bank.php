<?php
require_once 'roots.php';
global $pdo;

try {
    // Create a sample bank account
    $sql = "INSERT INTO accounts (
        account_code, 
        account_name, 
        account_type_id, 
        account_type, 
        category_id,
        description,
        opening_balance,
        current_balance,
        status,
        created_at
    ) VALUES (
        'BANK-001',
        'CRDB Bank - Main Account',
        1,
        'asset',
        NULL,
        'Main business bank account at CRDB',
        50000.00,
        50000.00,
        'active',
        NOW()
    )";
    
    $pdo->exec($sql);
    echo "✓ Sample bank account created successfully!\n";
    echo "Account Name: CRDB Bank - Main Account\n";
    echo "Account Code: BANK-001\n";
    echo "Balance: TSh 50,000.00\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
