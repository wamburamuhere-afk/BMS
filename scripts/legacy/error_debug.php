<?php
// error_debug.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/roots.php';
    require_once __DIR__ . '/api/helpers/transaction_helper.php';
    
    echo "Checking API file content...\n";
    $api_file = __DIR__ . '/api/account/update_expense.php';
    if (file_exists($api_file)) {
        echo "API File found. Testing inclusion...\n";
        include $api_file;
    } else {
        echo "API File NOT found at $api_file\n";
    }
} catch (Throwable $e) {
    echo "\n--- ERROR CAUGHT ---\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
?>
