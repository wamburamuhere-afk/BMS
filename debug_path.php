<?php
require_once 'roots.php';
$path = ROOT_DIR . '/app/bms/operations/assets.php';
echo "Path: $path\n";
echo "Exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
echo "Is File: " . (is_file($path) ? 'YES' : 'NO') . "\n";
echo "Roots Dir: " . ROOT_DIR . "\n";

// Check coming soon condition
$routes_check = $routes['assets'] ?? 'NOT SET';
echo "Route 'assets': $routes_check\n";

if ($routes_check !== 'NOT SET') {
    if (file_exists($routes_check)) {
        echo "Route file exists.\n";
    } else {
        echo "Route file MISSING. This triggers coming soon.\n";
    }
}
?>
