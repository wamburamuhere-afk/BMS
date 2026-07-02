<?php
// test_update.php
require_once 'roots.php';
global $pdo;

// Simulate a POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['user_id'] = 1; // Assuming 1 exists

// Prepare test data (adjust IDs based on your actual data)
$_POST = [
    'expense_id' => 6, // Use an ID from your 'expenses' table
    'expense_date' => date('Y-m-d'),
    'expense_account_id' => 5, // Use a valid account ID
    'amount' => 1500.50,
    'bank_account_id' => 5, // Use a valid bank account ID
    'description' => 'Test Repair Update 2',
    'status' => 'pending'
];

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Update API Execution...\n";
try {
    include 'api/account/update_expense.php';
} catch (Throwable $e) {
    echo "\nFATAL ERROR TRIGGERED:\n";
    echo $e->getMessage() . "\n";
    echo "In " . $e->getFile() . " on line " . $e->getLine() . "\n";
}
?>
