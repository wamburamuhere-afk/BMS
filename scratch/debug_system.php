<?php
/**
 * BMS Deep Diagnostic Tool
 * This script checks for common causes of 403 Forbidden errors.
 */
header('Content-Type: text/plain');
require_once __DIR__ . '/../roots.php';

echo "=== BMS SYSTEM DIAGNOSTIC ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "User: " . (get_current_user()) . "\n\n";

// 1. Check Key Files
$files_to_check = [
    'ROOT/roots.php' => ROOT_DIR . '/roots.php',
    'ROOT/index.php' => ROOT_DIR . '/index.php',
    'ROOT/.htaccess' => ROOT_DIR . '/.htaccess',
    'APP/assets.php' => ROOT_DIR . '/app/bms/operations/assets.php',
    'API/print_assets.php' => ROOT_DIR . '/api/operations/print_assets.php'
];

echo "--- File System Check ---\n";
foreach ($files_to_check as $label => $path) {
    if (file_exists($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        echo "[OK] $label exists. (Perms: $perms)\n";
    } else {
        echo "[ERROR] $label NOT FOUND at $path\n";
    }
}

// 2. Check Routing Logic
echo "\n--- Routing Check ---\n";
$test_routes = ['assets', 'print-assets', 'dashboard'];
foreach ($test_routes as $r) {
    if (isset($routes[$r])) {
        echo "[OK] Route '$r' maps to: " . $routes[$r] . "\n";
        if (!file_exists($routes[$r])) {
            echo "     !! WARNING: Target file missing!\n";
        }
    } else {
        echo "[FAIL] Route '$r' is NOT DEFINED in roots.php\n";
    }
}

// 3. Check Session & Permissions
echo "\n--- Auth/Session Check ---\n";
if (isset($_SESSION['user_id'])) {
    echo "[OK] User is logged in (ID: {$_SESSION['user_id']})\n";
    echo "Role ID: " . ($_SESSION['role_id'] ?? 'Not Set') . "\n";
    
    if (function_exists('canView')) {
        echo "Can view 'assets': " . (canView('assets') ? 'YES' : 'NO') . "\n";
    }
} else {
    echo "[WARNING] No active session found in this script.\n";
}

// 4. Check for Directory Collisions
echo "\n--- Directory Collision Check ---\n";
$potential_dirs = ['assets', 'dashboard', 'api'];
foreach ($potential_dirs as $d) {
    if (is_dir(ROOT_DIR . '/' . $d)) {
        echo "[ALERT] Directory '$d/' exists in root. This may cause 403 if it conflicts with a route.\n";
    }
}

echo "\n=== END OF DIAGNOSTIC ===\n";
?>
