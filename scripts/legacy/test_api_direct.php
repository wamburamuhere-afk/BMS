<?php
// Direct test of API without session
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['draw'] = 1;
$_GET['start'] = 0;
$_GET['length'] = 10;

// Capture all output
ob_start();

// Include the API file
include 'c:/wamp64/www/bms/api/get_purchase_returns.php';

$output = ob_get_clean();

// Save to file for inspection
file_put_contents('c:/wamp64/www/bms/api_output_test.txt', $output);

echo "Output saved to api_output_test.txt\n";
echo "Length: " . strlen($output) . " bytes\n";
echo "First 1000 chars:\n";
echo substr($output, 0, 1000) . "\n";

// Check if it's valid JSON
$json = json_decode($output, true);
if ($json === null) {
    echo "\nJSON ERROR: " . json_last_error_msg() . "\n";
    
    // Show non-printable characters
    echo "\nHex dump of first 200 bytes:\n";
    echo bin2hex(substr($output, 0, 200)) . "\n";
} else {
    echo "\nJSON is valid!\n";
    print_r($json);
}
