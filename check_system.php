// Try to locate roots.php
if (file_exists('app/bms/roots.php')) {
    require_once 'app/bms/roots.php';
} elseif (file_exists('roots.php')) {
    require_once 'roots.php';
} else {
    // Hardcoded absolute path fallback
    $root_path = __DIR__ . '/app/bms/roots.php';
    if (file_exists($root_path)) {
        require_once $root_path;
    } else {
        // Fallback for direct execution
        echo "Warning: roots.php not found. Continuing with manual DB connection...\n";
    }
}

// Locate db_connect.php
if (file_exists('includes/db_connect.php')) {
    require_once 'includes/db_connect.php';
} elseif (file_exists('app/bms/pos/includes/db_connect.php')) {
    require_once 'app/bms/pos/includes/db_connect.php';
} else {
    // Try to find it in common locations
    $possible_paths = [
        'app/bms/includes/db_connect.php',
        'app/bms/pos/db_connect.php',
        'db_connect.php'
    ];
    
    $found = false;
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        // If all else fails, create a manual connection for diagnosis
        echo "Creating manual DB connection...\n";
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

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            echo "Manual connection successful.\n";
        } catch (\PDOException $e) {
            die("Connection failed: " . $e->getMessage() . "\n");
        }
    }
}

header('Content-Type: text/plain');

echo "========================================\n";
echo "PAYROLL SYSTEM DIAGNOSTIC REPORT\n";
echo "========================================\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Check Tables Existence
echo "[1] CHECKING DATABASE TABLES...\n";
$tables = [
    'employees', 
    'payroll', 
    'tax_brackets',         // New
    'allowance_types',      // New
    'employee_allowances',  // New
    'payroll_settings'      // New
];

$missing_tables = [];
foreach ($tables as $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
        echo "OK: Table '$table' exists.\n";
    } catch (PDOException $e) {
        echo "ERROR: Table '$table' DOES NOT EXIST.\n";
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "\nCRITICAL: The database migration has NOT been run!\n";
    echo "The new flexible payroll system requires these tables.\n";
    echo "Please run: app/bms/pos/database/payroll_configuration_migration.sql\n\n";
} else {
    echo "\nSUCCESS: All required tables exist.\n\n";
}

// 2. Check Payroll Table Columns
echo "[2] CHECKING PAYROLL TABLE COLUMNS...\n";
$required_columns = ['payment_status', 'payment_date', 'approved_by', 'date_approved']; // columns we removed or use
$columns_found = [];

try {
    $stmt = $pdo->query("DESCRIBE payroll");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $columns_found = $columns;
    
    foreach ($required_columns as $col) {
        if (in_array($col, $columns)) {
            echo "Info: Column '$col' exists.\n";
        } else {
            echo "Warning: Column '$col' DOES NOT exist (this is okay if we removed it, but check code).\n";
            // date_approved and approved_by were removed/cleaned up in our previous edits if I recall correctly?
            // Wait, we REMOVED references to them in the API code because they DIDN'T exist.
            // So if they don't exist here, that is GOOD matching our code.
        }
    }
} catch (Exception $e) {
    echo "Error checking columns: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Check Data Counts
echo "[3] CHECKING DATA COUNTS...\n";
$active_employees = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
echo "Active Employees: $active_employees\n";

$payroll_records = $pdo->query("SELECT COUNT(*) FROM payroll")->fetchColumn();
echo "Total Payroll Records: $payroll_records\n";

$current_month = date('Y-m');
$current_period_records = $pdo->query("SELECT COUNT(*) FROM payroll WHERE payroll_period = '$current_month'")->fetchColumn();
echo "Records for $current_month: $current_period_records\n";

// 4. Check Tax Brackets Content
if (!in_array('tax_brackets', $missing_tables)) {
    echo "\n[4] CHECKING TAX CONFIGURATION...\n";
    $bracket_count = $pdo->query("SELECT COUNT(*) FROM tax_brackets WHERE is_active = 1")->fetchColumn();
    echo "Active Tax Brackets: $bracket_count\n";
    if ($bracket_count == 0) {
        echo "WARNING: No active tax brackets found. Tax calculation will fail or use default!\n";
    }
}

echo "\n========================================\n";
echo "SUMMARY & RECOMMENDATION\n";
echo "========================================\n";

if (!empty($missing_tables)) {
    echo "❌ SYSTEM BROKEN: Database tables are missing.\n";
    echo "ACTION: Run the migration script immediately.\n";
} elseif ($current_period_records == 0) {
    echo "⚠️ NO DATA: System is ready, but no payroll processing done for this month.\n";
    echo "ACTION: Go to Payroll -> Click 'Process Selected'.\n";
} else {
    echo "✅ SYSTEM OK: Database structure looks correct.\n";
    echo "If buttons are failing, it might be a browser cache issue.\n";
    echo "Try: Ctrl + F5 to hard refresh.\n";
}
