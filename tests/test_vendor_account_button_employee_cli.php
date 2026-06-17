<?php
/**
 * Step 3 — "View Account" button on the Employees list — CLI test
 *   php tests/test_vendor_account_button_employee_cli.php
 *
 * Verifies:
 *  1. employees.php card view has View Account → employee_statement?employee_id=X
 *  2. api/get_employees.php table dropdown has the same link
 *  3. employee_statement route is registered in roots.php
 *  4. app/constant/reports/employee_statement.php exists
 *  5. api/account/get_employee_statement.php exists and covers core logic
 *  6. api/account/search_employees.php exists
 *  7. Live-DB: a real employee with payroll resolves and returns statement data
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────────
section('1. employees.php card view — View Account button');
$empPageSrc = file_get_contents("$root/app/bms/pos/employees.php");
(strpos($empPageSrc, "getUrl('employee_statement') ?>?employee_id=<?= \$employee['employee_id'] ?>") !== false)
    ? pass('card view links to employee_statement with employee_id')
    : fail('View Account link missing or malformed in employees.php card view');
(strpos($empPageSrc, "bi-file-earmark-text") !== false)
    ? pass('card view icon bi-file-earmark-text present')
    : fail('icon missing in employees.php card view');

// ─────────────────────────────────────────────────────────────────────────────
section('2. api/get_employees.php table dropdown — View Account item');
$getEmpSrc = file_get_contents("$root/api/get_employees.php");
(strpos($getEmpSrc, 'employee_statement') !== false)
    ? pass('api/get_employees.php references employee_statement')
    : fail('View Account link missing from api/get_employees.php table dropdown');
(strpos($getEmpSrc, '$empStatementUrl') !== false)
    ? pass('$empStatementUrl variable built for the link')
    : fail('$empStatementUrl not found');

// ─────────────────────────────────────────────────────────────────────────────
section('3. Route registration in roots.php');
$routesSrc = file_get_contents("$root/roots.php");
(strpos($routesSrc, "'employee_statement' => REPORTS_DIR . '/employee_statement.php'") !== false)
    ? pass("employee_statement route registered")
    : fail('employee_statement route missing from roots.php');

// ─────────────────────────────────────────────────────────────────────────────
section('4. New files exist');
$files = [
    'app/constant/reports/employee_statement.php'  => 'UI page',
    'api/account/get_employee_statement.php'        => 'API endpoint',
    'api/account/search_employees.php'             => 'search endpoint',
];
foreach ($files as $rel => $label) {
    file_exists("$root/$rel")
        ? pass("$label ($rel) exists")
        : fail("$label ($rel) MISSING");
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. get_employee_statement.php — core logic checks');
$apiSrc = file_get_contents("$root/api/account/get_employee_statement.php");
(strpos($apiSrc, 'canView(\'financial_reports\')') !== false)
    ? pass('permission gate: canView(financial_reports)')
    : fail('permission gate missing');
(strpos($apiSrc, 'net_salary') !== false)
    ? pass('net_salary used as charge/payment amount')
    : fail('net_salary not referenced');
(strpos($apiSrc, "payment_status = 'paid'") !== false)
    ? pass("payment leg filters payment_status='paid'")
    : fail("payment_status='paid' filter missing");
(strpos($apiSrc, 'opening_balance') !== false)
    ? pass('opening_balance computed')
    : fail('opening_balance missing');

// ─────────────────────────────────────────────────────────────────────────────
section('6. search_employees.php — basic checks');
$searchSrc = file_get_contents("$root/api/account/search_employees.php");
(strpos($searchSrc, 'employees') !== false)
    ? pass('queries employees table')
    : fail('employees table reference missing');
(strpos($searchSrc, "'results'") !== false)
    ? pass("returns Select2 'results' key")
    : fail("'results' key missing");

// ─────────────────────────────────────────────────────────────────────────────
section('7. Live-DB — employee with payroll resolves and returns statement');
$empId = (int)($pdo->query("
    SELECT e.employee_id
      FROM employees e
      JOIN payroll p ON p.employee_id = e.employee_id AND p.status IN ('approved','paid')
     WHERE e.status != 'terminated'
     LIMIT 1
")->fetchColumn() ?: 0);

if (!$empId) {
    echo "  \033[33m⚠️  No active employee with payroll found — skipping live-DB checks\033[0m\n";
} else {
    $nameRow = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM employees WHERE employee_id = ?");
    $nameRow->execute([$empId]);
    $empName = (string)$nameRow->fetchColumn();
    $empName ? pass("employee #$empId resolves to '$empName'") : fail("employee #$empId not found");

    // Count payroll charges
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id = ? AND status IN ('approved','paid')");
    $cnt->execute([$empId]);
    $chargeCount = (int)$cnt->fetchColumn();
    ($chargeCount > 0) ? pass("employee #$empId has $chargeCount qualifying payroll run(s)") : fail('no qualifying payroll runs found');

    // Verify opening-balance query runs without error
    try {
        $ob = $pdo->prepare("
            SELECT
              (SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE employee_id = ? AND status IN ('approved','paid') AND payroll_date < ?)
              -
              (SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE employee_id = ? AND payment_status = 'paid' AND payment_date IS NOT NULL AND payment_date < ?)
            AS opening
        ");
        $ob->execute([$empId, date('Y-01-01'), $empId, date('Y-01-01')]);
        $opening = (float)$ob->fetchColumn();
        pass("opening-balance query runs cleanly (opening = " . number_format($opening, 2) . ")");
    } catch (Throwable $e) {
        fail('opening-balance query threw: ' . $e->getMessage());
    }
}
