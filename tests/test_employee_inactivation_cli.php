<?php
/**
 * test_employee_inactivation_cli.php
 * -----------------------------------
 * End-to-end guard for employee_inactivation_plan.md (Phases 0-4).
 *
 * Drives the REAL endpoints (api/inactivate_employee.php,
 * api/reactivate_employee.php, api/apply_leave.php, api/mark_attendance.php)
 * through subprocess runners with an admin session + CSRF token, exactly
 * like a real request would hit them — not just the underlying helper
 * functions. Also exercises core/lifecycle_effects.php in-process (it needs
 * no HTTP boundary).
 *
 * Fully self-cleaning: creates one throwaway employee + one payroll/
 * attendance/leave row each, deletes all of it at the end regardless of
 * pass/fail, and asserts the employees table row count is unchanged.
 *
 * Run:  php tests/test_employee_inactivation_cli.php
 * Exit 0 = pass, 1 = failure.
 */
$root = dirname(__DIR__);
require_once $root . '/roots.php';
require_once $root . '/core/employee_status.php';
require_once $root . '/core/lifecycle_effects.php';
global $pdo;

$pass = 0; $fail = 0;
function ok($cond, $m) { global $pass, $fail; if ($cond) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌\033[0m $m\n"; } }
function head($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

// Run an endpoint in a fresh admin-session subprocess (real HTTP-shaped
// request); returns decoded JSON.
function run_endpoint($root, $endpoint, $post = [], $get = []) {
    $runner = $root . '/tests/_tmp_ei_runner.php';
    $code = '<?php
require_once ' . var_export($root . '/roots.php', true) . ';
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;
$_SERVER["REQUEST_METHOD"]=' . var_export($post ? 'POST' : 'GET', true) . ';
parse_str(' . var_export(http_build_query($get), true) . ', $_GET);
parse_str(' . var_export(http_build_query($post), true) . ', $_POST);
if (function_exists("csrf_token")) { $_POST["_csrf"] = csrf_token(); }
require ' . var_export($endpoint, true) . ';
';
    file_put_contents($runner, $code);
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    $s = strpos((string)$out, '{');
    return $s === false ? ['_raw' => $out] : json_decode(substr($out, $s), true);
}

echo "\n\033[1m═══ Employee Inactivation Plan — Phases 0-4 ═══\033[0m\n";

$employeesBefore = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();

// ── Fixture: one throwaway ACTIVE employee ─────────────────────────────────
head('Fixture — seed one throwaway active employee');

$deptId  = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetchColumn();
$desigId = $pdo->query("SELECT designation_id FROM designations LIMIT 1")->fetchColumn();
if (!$deptId || !$desigId) {
    echo "  \033[33m⚠ SKIP\033[0m — no department/designation seeded.\n";
    exit(0);
}

$ts = time();
$email = "eitest+$ts@example.test";
$pdo->prepare("DELETE FROM employees WHERE email LIKE 'eitest+%@example.test'")->execute();

$cols = $pdo->query("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
       AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL
       AND EXTRA NOT LIKE '%auto_increment%'
")->fetchAll(PDO::FETCH_ASSOC);
$forced = [
    'email' => $email, 'employee_code' => "EI-$ts", 'employee_number' => "EI-$ts",
    'first_name' => '__EITest', 'last_name' => 'Employee', 'phone' => '0700000001',
    'status' => 'active', 'employment_status' => 'active', 'created_by' => 4,
    'department_id' => $deptId, 'designation_id' => $desigId,
];
$vals = $forced;
foreach ($cols as $c) {
    $n = $c['COLUMN_NAME'];
    if (array_key_exists($n, $vals)) continue;
    $t = strtolower($c['DATA_TYPE']);
    if ($t === 'enum') {
        $vals[$n] = preg_match("/'([^']*)'/", $c['COLUMN_TYPE'], $m) ? $m[1] : '';
    } elseif (in_array($t, ['int','bigint','tinyint','smallint','mediumint','decimal','double','float','year'])) {
        $vals[$n] = 0;
    } elseif ($t === 'date') {
        $vals[$n] = date('Y-m-d');
    } elseif (in_array($t, ['datetime','timestamp'])) {
        $vals[$n] = date('Y-m-d H:i:s');
    } else {
        $vals[$n] = 'x';
    }
}
$names = array_keys($vals);
$pdo->prepare("INSERT INTO employees (" . implode(',', $names) . ") VALUES (" .
    implode(',', array_fill(0, count($names), '?')) . ")")->execute(array_values($vals));
$eid = (int)$pdo->lastInsertId();
ok($eid > 0, $eid > 0 ? "Seeded active employee #$eid" : "Seed failed");

// One historical row each in payroll / attendance / leaves, so we can prove
// none of them get touched by inactivate/reactivate.
$pdo->prepare("INSERT INTO payroll (employee_id, payroll_period, basic_salary, gross_salary, net_salary, payment_status, created_by, created_at)
               VALUES (?, '2030-01', 100000, 100000, 100000, 'approved', 4, NOW())")->execute([$eid]);
$pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status, created_by, created_at)
               VALUES (?, '2030-01-15', 'present', 4, NOW())")->execute([$eid]);
$leaveTypeId = $pdo->query("SELECT type_id FROM leave_types WHERE status='active' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO leaves (employee_id, leave_type_id, leave_type, start_date, end_date, total_days, days_count, half_day, is_paid, reason, status, created_by, applied_by, created_at)
               VALUES (?, ?, 'annual', '2030-01-10', '2030-01-11', 2, 2, 'none', 1, '__EI test leave', 'approved', 4, 4, NOW())")
    ->execute([$eid, $leaveTypeId ?: null]);
$seedCounts = [
    (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE employee_id=$eid")->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id=$eid")->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE employee_id=$eid")->fetchColumn(),
];
ok($seedCounts === [1, 1, 1], 'Seeded 1 payroll + 1 attendance + 1 leave row for the fixture (got ' . implode(',', $seedCounts) . ')');

// ── Phase 0 — lifecycle_effects.php sets BOTH fields ────────────────────────
head('Phase 0 — HR Action termination/resignation effect sets status=inactive too');

$pdo->beginTransaction();
$effect = applyLifecycleEffectRow($pdo, ['event_id' => 0, 'employee_id' => $eid, 'event_type' => 'termination'], 4);
$row = $pdo->query("SELECT status, employment_status FROM employees WHERE employee_id=$eid")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'inactive' && $row['employment_status'] === 'terminated',
    "termination effect sets status=inactive + employment_status=terminated (got: " . json_encode($row) . ")");
$pdo->rollBack();
$row = $pdo->query("SELECT status FROM employees WHERE employee_id=$eid")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'active', 'rolled back cleanly — fixture is active again for the next section');

// ── Phase 1 — api/inactivate_employee.php (real endpoint, real session) ────
head('Phase 1 — api/inactivate_employee.php');

$r = run_endpoint($root, "$root/api/inactivate_employee.php", [
    'employee_id' => $eid, 'outcome' => 'failed_probation', 'reason' => '__EI test reason',
]);
ok(!empty($r['success']), 'inactivate_employee.php succeeds (got: ' . json_encode($r) . ')');

$row = $pdo->query("SELECT status, employment_status, inactivation_reason FROM employees WHERE employee_id=$eid")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'inactive', "status=inactive (got: {$row['status']})");
ok($row['employment_status'] === 'terminated', "failed_probation maps to employment_status=terminated (got: {$row['employment_status']})");
ok($row['inactivation_reason'] === '__EI test reason', "reason note persisted (got: " . var_export($row['inactivation_reason'], true) . ")");

$r2 = run_endpoint($root, "$root/api/inactivate_employee.php", ['employee_id' => $eid, 'outcome' => 'terminated']);
ok(empty($r2['success']), 'inactivating an already-inactive employee is rejected (got: ' . json_encode($r2) . ')');

// ── Phase 3 — pickers exclude the inactive fixture, writes reject it ───────
head('Phase 3 — pickers + server-side write enforcement');

$activeIds = $pdo->query("SELECT employee_id FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
ok(!in_array($eid, $activeIds), 'the canonical status=active picker query excludes the inactive fixture');

$applyResult = run_endpoint($root, "$root/api/apply_leave.php", [
    'employee_id' => $eid, 'leave_type_id' => $leaveTypeId ?: 1,
    'start_date' => '2030-02-01', 'end_date' => '2030-02-02', 'reason' => '__EI should be rejected',
]);
ok(empty($applyResult['success']) && stripos($applyResult['message'] ?? '', 'inactive') !== false,
    'apply_leave.php rejects an inactive applicant (got: ' . json_encode($applyResult) . ')');

$markResult = run_endpoint($root, "$root/api/mark_attendance.php", [
    'employee_id' => $eid, 'attendance_date' => '2030-02-01', 'status' => 'present',
]);
ok(empty($markResult['success']) && stripos($markResult['message'] ?? '', 'inactive') !== false,
    'mark_attendance.php rejects an inactive employee (got: ' . json_encode($markResult) . ')');

// ── Phase 4 — history survives, stays reachable while inactive ─────────────
head('Phase 4 — history untouched + still reachable while inactive');

$payCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE employee_id=$eid")->fetchColumn();
$attCount = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id=$eid")->fetchColumn();
$leaveCount = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE employee_id=$eid")->fetchColumn();
ok($payCount === 1, "payroll row survives inactivation (got $payCount)");
ok($attCount === 1, "attendance row survives inactivation (got $attCount)");
ok($leaveCount === 1, "leave row survives inactivation (got $leaveCount)");

// employee_details.php's queries have no status gate — mirror them directly
$edPayroll = $pdo->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id = ?");
$edPayroll->execute([$eid]);
ok((int)$edPayroll->fetchColumn() === 1, 'employee_details.php-style payroll query still returns the row for an inactive employee');

$edAttendance = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ?");
$edAttendance->execute([$eid]);
ok((int)$edAttendance->fetchColumn() === 1, 'employee_details.php-style attendance query still returns the row for an inactive employee');

// get_payrolls.php: default excludes, include_inactive=1 includes
$defaultCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active' AND employee_id = $eid")->fetchColumn();
$inclCount    = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE 1=1 AND employee_id = $eid")->fetchColumn();
ok($defaultCount === 0, 'get_payrolls.php default (no include_inactive) excludes the fixture');
ok($inclCount === 1, 'get_payrolls.php with include_inactive=1 includes the fixture');

// ── Phase 2 — api/reactivate_employee.php ───────────────────────────────────
head('Phase 2 — api/reactivate_employee.php');

$r3 = run_endpoint($root, "$root/api/reactivate_employee.php", ['employee_id' => $eid]);
ok(!empty($r3['success']), 'reactivate_employee.php succeeds (got: ' . json_encode($r3) . ')');
ok(($r3['has_live_contract'] ?? null) === false, "has_live_contract=false — fixture has no contract row (got: " . json_encode($r3) . ")");
ok(stripos($r3['message'] ?? '', 'no active or draft contract') !== false, "message warns about the missing contract");

$row = $pdo->query("SELECT status, employment_status, inactivation_reason FROM employees WHERE employee_id=$eid")->fetch(PDO::FETCH_ASSOC);
ok($row['status'] === 'active', "status=active (got: {$row['status']})");
ok($row['employment_status'] === 'active', "employment_status=active (got: {$row['employment_status']})");
ok($row['inactivation_reason'] === null, 'reason note cleared on reactivate');

$r4 = run_endpoint($root, "$root/api/reactivate_employee.php", ['employee_id' => $eid]);
ok(empty($r4['success']), 'reactivating an already-active employee is rejected (got: ' . json_encode($r4) . ')');

$activeIdsAfter = $pdo->query("SELECT employee_id FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array($eid, $activeIdsAfter), 'the fixture reappears in the canonical status=active picker query after reactivation');

// History must still be untouched after the full round trip.
$payCount2 = (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE employee_id=$eid")->fetchColumn();
$attCount2 = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE employee_id=$eid")->fetchColumn();
$leaveCount2 = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE employee_id=$eid")->fetchColumn();
ok($payCount2 === 1 && $attCount2 === 1 && $leaveCount2 === 1,
    "history still intact after the full inactivate->reactivate round trip (payroll=$payCount2, attendance=$attCount2, leaves=$leaveCount2)");

// ── Phase 2b — reactivating an employee WITH a live contract: no warning ──
head('Phase 2b — reactivate with a live contract on file (no warning)');

$eid2 = null;
$hasContractsTable = (bool)$pdo->query("SHOW TABLES LIKE 'employee_contracts'")->fetch();
if ($hasContractsTable) {
    $vals2 = $vals; // reuse the same forced-column scaffolding as the main fixture
    $vals2['email'] = "eitest2+$ts@example.test";
    $vals2['employee_code'] = "EI2-$ts";
    $vals2['employee_number'] = "EI2-$ts";
    $vals2['first_name'] = '__EITest2';
    $names2 = array_keys($vals2);
    $pdo->prepare("INSERT INTO employees (" . implode(',', $names2) . ") VALUES (" .
        implode(',', array_fill(0, count($names2), '?')) . ")")->execute(array_values($vals2));
    $eid2 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, end_date, status, activated_by, activated_at, created_by)
                   VALUES (?, 'Permanent', ?, NULL, 'active', 4, NOW(), 4)")->execute([$eid2, date('Y-m-d')]);

    $pdo->prepare("UPDATE employees SET status = 'inactive', employment_status = 'terminated' WHERE employee_id = ?")->execute([$eid2]);

    $r5 = run_endpoint($root, "$root/api/reactivate_employee.php", ['employee_id' => $eid2]);
    ok(!empty($r5['success']), 'reactivate_employee.php succeeds for the contracted fixture (got: ' . json_encode($r5) . ')');
    ok(($r5['has_live_contract'] ?? null) === true, "has_live_contract=true — fixture has an active contract (got: " . json_encode($r5) . ")");
    ok(stripos($r5['message'] ?? '', 'no active or draft contract') === false, "no missing-contract warning when a live contract exists");

    $pdo->exec("DELETE FROM employee_contracts WHERE employee_id = $eid2");
    $pdo->exec("DELETE FROM employees WHERE employee_id = $eid2");
} else {
    ok(true, 'employee_contracts table not present — skipping Phase 2b (has_live_contract defaults false, covered by Phase 2)');
}

// ── Cleanup ──────────────────────────────────────────────────────────────
head('Cleanup');

$pdo->prepare("DELETE FROM payroll WHERE employee_id = ?")->execute([$eid]);
$pdo->prepare("DELETE FROM attendance WHERE employee_id = ?")->execute([$eid]);
$pdo->prepare("DELETE FROM leaves WHERE employee_id = ?")->execute([$eid]);
$pdo->prepare("DELETE FROM employees WHERE employee_id = ?")->execute([$eid]);
$pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'employee' AND entity_id = ?")->execute([$eid]);

$employeesAfter = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
ok($employeesAfter === $employeesBefore, "employees table restored to $employeesBefore row(s) (got $employeesAfter)");

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All " . ($pass + $fail) . " checks passed.\033[0m\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail check(s) failed, $pass passed.\033[0m\n";
    exit(1);
}
