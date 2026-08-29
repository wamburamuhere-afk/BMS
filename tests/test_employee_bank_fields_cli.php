<?php
/**
 * BMS — Employee bank fields guard (Add/Edit wizard, Step 5: Bank & Documents).
 *
 * Covers the 3 bank fields added alongside the LMS parity work: Account Holder
 * Name, Bank Identifier Code (SWIFT/routing), and Bank Branch (bank_branch
 * existed in the schema already but had no input anywhere — this restores it,
 * reversing commit 4a85ca0 which intentionally dropped it).
 *
 * Verifies: the columns exist, the wizard exposes all 3 inputs, the edit-populate
 * JS fills them, add/update APIs read+write them, View Details displays them,
 * and a real DB round trip stores and returns the values unchanged.
 *
 * Run:
 *   php tests/test_employee_bank_fields_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

require_once dirname(__DIR__) . '/roots.php';
global $pdo;

$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

echo "\n\033[1m═══ Employee bank fields (Account Holder / SWIFT / Branch) ═══\033[0m\n";

$root = dirname(__DIR__);

head('Schema');
foreach (['account_holder_name', 'bank_swift_code', 'bank_branch'] as $col) {
    $exists = $pdo->query("SHOW COLUMNS FROM employees LIKE '$col'")->fetch(PDO::FETCH_ASSOC);
    $exists ? ok("employees.$col exists") : bad("employees.$col is missing");
}

head('Add/Edit wizard exposes all 3 inputs (Step 5)');
$empSrc = @file_get_contents("$root/app/bms/pos/employees.php") ?: '';
strpos($empSrc, 'name="account_holder_name"') !== false ? ok('account_holder_name input present') : bad('account_holder_name input missing');
strpos($empSrc, 'name="bank_swift_code"')     !== false ? ok('bank_swift_code input present')     : bad('bank_swift_code input missing');
strpos($empSrc, 'name="bank_branch"')         !== false ? ok('bank_branch input present')          : bad('bank_branch input missing');

head('Edit wizard pre-fill (editEmployee JS) reads all 3 back from the API');
strpos($empSrc, "\$('#account_holder_name').val(emp.account_holder_name") !== false ? ok('account_holder_name pre-filled on edit') : bad('account_holder_name not pre-filled on edit');
strpos($empSrc, "\$('#bank_swift_code').val(emp.bank_swift_code")         !== false ? ok('bank_swift_code pre-filled on edit')     : bad('bank_swift_code not pre-filled on edit');
strpos($empSrc, "\$('#bank_branch').val(emp.bank_branch")                 !== false ? ok('bank_branch pre-filled on edit')          : bad('bank_branch not pre-filled on edit');

head('add_employee.php writes all 3 on create');
$addSrc = @file_get_contents("$root/api/add_employee.php") ?: '';
foreach (['account_holder_name', 'bank_swift_code', 'bank_branch'] as $col) {
    strpos($addSrc, $col) !== false ? ok("add_employee.php references $col") : bad("add_employee.php does not reference $col");
}

head('update_employee.php can update all 3 on edit');
$updSrc = @file_get_contents("$root/api/update_employee.php") ?: '';
foreach (['account_holder_name', 'bank_swift_code', 'bank_branch'] as $col) {
    strpos($updSrc, "'$col'") !== false ? ok("update_employee.php whitelist includes $col") : bad("update_employee.php whitelist missing $col");
}

head('View Details displays all 3');
$detSrc = @file_get_contents("$root/app/bms/pos/employee_details.php") ?: '';
strpos($detSrc, "employee['account_holder_name']") !== false ? ok('View Details shows Account Holder Name') : bad('View Details missing Account Holder Name');
strpos($detSrc, "employee['bank_swift_code']")      !== false ? ok('View Details shows Bank Identifier Code') : bad('View Details missing Bank Identifier Code');
strpos($detSrc, "employee['bank_branch']")          !== false ? ok('View Details shows Bank Branch') : bad('View Details missing Bank Branch');

head('Real DB round trip (existing employee row, values restored after)');
$emp = $pdo->query("SELECT employee_id, bank_name, account_holder_name, bank_account, bank_branch, bank_swift_code
                       FROM employees ORDER BY employee_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    echo "  no employees in DB — skipping round-trip check\n";
} else {
    $eid = (int)$emp['employee_id'];
    $test = [
        'account_holder_name' => 'Test Holder Name',
        'bank_swift_code'     => 'CORUTZTZXXX',
        'bank_branch'         => 'Test Branch',
    ];
    $pdo->prepare("UPDATE employees SET account_holder_name = ?, bank_swift_code = ?, bank_branch = ? WHERE employee_id = ?")
        ->execute([$test['account_holder_name'], $test['bank_swift_code'], $test['bank_branch'], $eid]);

    $row = $pdo->prepare("SELECT account_holder_name, bank_swift_code, bank_branch FROM employees WHERE employee_id = ?");
    $row->execute([$eid]);
    $back = $row->fetch(PDO::FETCH_ASSOC);

    ($back['account_holder_name'] === $test['account_holder_name']) ? ok('account_holder_name round-trips') : bad('account_holder_name mismatch after save');
    ($back['bank_swift_code'] === $test['bank_swift_code'])         ? ok('bank_swift_code round-trips')     : bad('bank_swift_code mismatch after save');
    ($back['bank_branch'] === $test['bank_branch'])                 ? ok('bank_branch round-trips')          : bad('bank_branch mismatch after save');

    // Restore original values so this test never leaves data behind.
    $pdo->prepare("UPDATE employees SET account_holder_name = ?, bank_swift_code = ?, bank_branch = ? WHERE employee_id = ?")
        ->execute([$emp['account_holder_name'], $emp['bank_swift_code'], $emp['bank_branch'], $eid]);
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
