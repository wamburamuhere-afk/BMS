<?php
// Script to check system status from INSIDE the app (app/bms/pos/system_status.php)

// Try to include necessary files
$roots_path = '../../../../roots.php'; // 4 levels up? No, app/bms/pos -> bms root is 3 levels.
// Let's try multiple paths
$possible_roots = [
    '../../../roots.php',
    '../../roots.php', 
    '../roots.php',
    'roots.php'
];

foreach ($possible_roots as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// DB Connection
$db_connected = false;
$possible_db = [
    '../../../includes/db_connect.php',
    '../../includes/db_connect.php',
    '../includes/db_connect.php',
    'includes/db_connect.php'
];

foreach ($possible_db as $path) {
    if (file_exists($path)) {
        require_once $path; 
        $db_connected = true;
        break;
    }
}

// Manual Fallback if file not found
if (!$db_connected && !isset($pdo)) {
    try {
        $host = 'localhost';
        $db   = 'bms';
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, $user, $pass, $options);
        $db_connected = true;
    } catch (\PDOException $e) {
        die("❌ DB Connection Failed: " . $e->getMessage() . "\nPlease check your database settings.");
    }
}

// Force text plain for easy reading
header('Content-Type: text/plain');

echo "========================================\n";
echo "PAYROLL SYSTEM STATUS REPORT\n";
echo "========================================\n";

if ($db_connected) {
    echo "✅ Database Connected Successfully\n";
}

// 1. DATABASE TABLES CHECK
echo "\n[1] CHECKING TABLES...\n";
$tables = ['payroll', 'tax_brackets', 'payroll_settings'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table' exists. Records: $count\n";
    } catch (Exception $e) {
        echo "❌ Table '$table' MISSING! Error: " . $e->getMessage() . "\n";
    }
}

// 2. PAYROLL COLUMNS CHECK
echo "\n[2] CHECKING PAYROLL COLUMNS...\n";
try {
    $stmt = $pdo->query("DESCRIBE payroll");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['payment_status', 'payment_date'];
    
    foreach ($required as $req) {
        if (in_array($req, $columns)) {
            echo "✅ Column '$req' exists.\n";
        } else {
            echo "❌ Column '$req' MISSING!\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Could not check columns.\n";
}

// 3. CURRENT MONTH DATA
echo "\n[3] CURRENT MONTH DATA (" . date('Y-m') . ")\n";
try {
    $month = date('Y-m');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE payroll_period = ?");
    $stmt->execute([$month]);
    $count = $stmt->fetchColumn();
    echo "Records for $month: $count\n";
    
    if ($count == 0) {
        echo "⚠️ WARNING: No payroll records found for this month.\n";
        echo "👉 SOLUTION: You MUST click 'Process Selected' in the payroll page first.\n";
    } else {
        echo "✅ Data exists. Status updates should work.\n";
        
        // Show status breakdown
        $stmt = $pdo->prepare("SELECT payment_status, COUNT(*) as c FROM payroll WHERE payroll_period = ? GROUP BY payment_status");
        $stmt->execute([$month]);
        $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        print_r($statuses);
    }
} catch (Exception $e) {
    echo "Error checking data: " . $e->getMessage();
}

