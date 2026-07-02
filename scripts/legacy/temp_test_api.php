<?php
require_once 'roots.php';

// Test buildUrl function
echo "Testing buildUrl function:\n";
echo "buildUrl('api/account/get_invoices.php') = " . buildUrl('api/account/get_invoices.php') . "\n\n";

// Test if API file exists
$api_path = __DIR__ . '/api/account/get_invoices.php';
echo "API file path: $api_path\n";
echo "API file exists: " . (file_exists($api_path) ? 'YES' : 'NO') . "\n\n";

// Test API directly
echo "Testing API response:\n";
$_GET['draw'] = 1;
$_GET['start'] = 0;
$_GET['length'] = 10;

ob_start();
include 'api/account/get_invoices.php';
$response = ob_get_clean();

echo "API Response:\n";
echo $response . "\n";
