<?php
// scope-audit: skip — developer debug script; not a runtime data endpoint
// Define path to roots.php based on location
require_once __DIR__ . '/../../../roots.php';

echo "<h1>Payroll Deep Debugger</h1>";
echo "<pre>";

try {
    // 1. Check DB Connection
    if ($pdo) {
        echo "✅ Database Connection: SUCCESS\n";
    } else {
        die("❌ Database Connection: FAILED\n");
    }

    // 2. Check Total Employees
    $stmt = $pdo->query("SELECT count(*) FROM employees");
    $total = $stmt->fetchColumn();
    echo "📊 Total Employees in DB: $total\n";

    // 3. Check Active Employees (Case Sensitive Check)
    $stmt = $pdo->query("SELECT count(*) FROM employees WHERE status = 'active'");
    $active = $stmt->fetchColumn();
    echo "📊 Active Employees (lowercase 'active'): $active\n";

    $stmt = $pdo->query("SELECT count(*) FROM employees WHERE status = 'Active'");
    $activeCap = $stmt->fetchColumn();
    echo "📊 Active Employees (Capital 'Active'): $activeCap\n";

    // 4. Test API Logic Query
    echo "\n🔍 Testing Logic Query:\n";
    $query = "SELECT e.employee_id, e.first_name, e.last_name, e.basic_salary, e.status 
              FROM employees e 
              WHERE e.status = 'active'";
    
    echo "Query: $query\n";
    
    $stmt = $pdo->query($query);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "🔢 Rows Returned: " . count($employees) . "\n";
    
    if (count($employees) > 0) {
        echo "✅ Data Found! Sample First Row:\n";
        print_r($employees[0]);
    } else {
        echo "❌ No Data Found via logic query.\n";
        
        // Show actual statuses
        echo "\nDumping actual statuses from DB:\n";
        $stmt = $pdo->query("SELECT employee_id, status FROM employees LIMIT 5");
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($statuses);
    }

    // 5. Check Allowances Table
    echo "\n🔍 Checking Allowance Table:\n";
    try {
        $stmt = $pdo->query("SELECT count(*) FROM employee_allowances");
        echo "✅ employee_allowances table exists. Rows: " . $stmt->fetchColumn() . "\n";
    } catch (Exception $e) {
        echo "❌ employee_allowances table ERROR: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage();
}

echo "</pre>";
?>
