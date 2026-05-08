<?php
// Mock session
session_start();
$_SESSION['user_id'] = 1; // Assuming admin/valid user
$_SESSION['role_name'] = 'Admin';
$_SESSION['logged_in'] = true;

// Define helper directly to avoid path issues during test if needed, but try requiring
require_once '../roots.php';

// Capture output
ob_start();
require 'get_grns.php';
$output = ob_get_clean();

file_put_contents('debug_api_output.txt', $output);
echo "Output captured. Length: " . strlen($output) . "\n";
echo "First 100 chars: " . substr($output, 0, 100) . "\n";
?>
