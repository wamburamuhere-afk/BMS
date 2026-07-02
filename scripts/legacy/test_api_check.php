<?php
// Mock GET request
$_GET['category'] = 0;
$_GET['search'] = '';

// Capture output
ob_start();
require_once __DIR__ . '/api/pos/simple_products.php';
$output = ob_get_clean();

echo "API Output Length: " . strlen($output) . "\n";
echo "First 100 chars: " . substr($output, 0, 100) . "\n";

$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "JSON Decode: FAST AND VALID\n";
    if (isset($json['data'][0])) {
        echo "First Product Price Type: " . gettype($json['data'][0]['selling_price']) . "\n";
        echo "First Product Price Value: " . $json['data'][0]['selling_price'] . "\n";
    } else {
        echo "No products found in data.\n";
    }
} else {
    echo "JSON Decode: FAILED - " . json_last_error_msg() . "\n";
    echo "Full Output:\n" . $output;
}
?>
