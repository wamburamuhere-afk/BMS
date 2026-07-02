<?php
// Test file to check purchase_returns API
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/roots.php';

header('Content-Type: text/plain');

echo "=== Testing Purchase Returns API ===\n\n";

// Check if table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'purchase_returns'");
    $exists = $stmt->fetch();
    if ($exists) {
        echo "✓ Table 'purchase_returns' exists\n\n";
        
        // Get columns
        $stmt = $pdo->query("DESCRIBE purchase_returns");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Columns in purchase_returns:\n";
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
        echo "\n";
        
        // Count records
        $stmt = $pdo->query("SELECT COUNT(*) FROM purchase_returns");
        $count = $stmt->fetchColumn();
        echo "Total records: $count\n\n";
        
    } else {
        echo "✗ Table 'purchase_returns' does NOT exist\n";
    }
    
    // Check purchase_return_items table
    $stmt = $pdo->query("SHOW TABLES LIKE 'purchase_return_items'");
    $exists = $stmt->fetch();
    if ($exists) {
        echo "✓ Table 'purchase_return_items' exists\n";
    } else {
        echo "✗ Table 'purchase_return_items' does NOT exist\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Now test the actual API call
echo "\n=== Testing API Response ===\n";
$_GET['draw'] = 1;
$_GET['start'] = 0;
$_GET['length'] = 10;

ob_start();
include __DIR__ . '/api/get_purchase_returns.php';
$response = ob_get_clean();

echo "Response length: " . strlen($response) . " bytes\n";
echo "First 500 chars:\n";
echo substr($response, 0, 500) . "\n";

// Try to decode JSON
$decoded = json_decode($response, true);
if ($decoded === null) {
    echo "\n✗ JSON decode failed!\n";
    echo "JSON error: " . json_last_error_msg() . "\n";
} else {
    echo "\n✓ JSON is valid\n";
    echo "Keys: " . implode(', ', array_keys($decoded)) . "\n";
}
