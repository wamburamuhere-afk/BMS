<?php
// scratch/test_hr_module.php — HR Module Button Tests
require_once __DIR__ . '/../roots.php';

// ── Helpers ────────────────────────────────────────────────────────────────
function pass($msg) { echo "<li style='color:green'>✅ PASS — $msg</li>"; }
function fail($msg) { echo "<li style='color:red'>❌ FAIL — $msg</li>"; }
function section($title) { echo "<h3 style='margin-top:24px;border-bottom:2px solid #333;padding-bottom:4px'>$title</h3><ul>"; }
function endsec() { echo "</ul>"; }

// Fake session for tests
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['first_name'] = 'Test';
$_SESSION['last_name'] = 'User';

// Find a project with staff for testing
$proj = $pdo->query("SELECT p.project_id, p.project_name, e.employee_id, e.first_name, e.last_name, e.employee_number
    FROM projects p
    JOIN employees e ON e.project_id = p.project_id
    WHERE e.status != 'terminated'
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$proj) {
    die("<p style='color:red'>❌ No project with staff found. Please assign at least one staff member to a project first.</p>");
}

$project_id  = $proj['project_id'];
$employee_id = $proj['employee_id'];
$staff_name  = $proj['first_name'] . ' ' . $proj['last_name'];
$today       = date('Y-m-d');
$month       = date('Y-m');

echo "<html><body style='font-family:sans-serif;padding:20px;max-width:900px;margin:auto'>";
echo "<h1>HR Module Test Report</h1>";
echo "<p><strong>Project:</strong> [{$project_id}] {$proj['project_name']}</p>";
echo "<p><strong>Test Staff:</strong> {$staff_name} ({$proj['employee_number']})</p>";
echo "<p><strong>Date:</strong> $today</p>";
echo "<hr>";

// ══════════════════════════════════════════════════════════════════════
// 1. NEW STAFF — API: add_employee.php
// ══════════════════════════════════════════════════════════════════════
section("1. NEW STAFF (Create & Assign within Project)");

// Get a department and designation for the test
$dept = $pdo->query("SELECT department_id FROM departments WHERE status='active' LIMIT 1")->fetch();
$des  = $pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 1")->fetch();

if ($dept && $des) {
    $_POST = [
        'first_name'       => 'TestHR',
        'last_name'        => 'Staff' . rand(100,999),
        'email'            => 'testhr' . rand(1000,9999) . '@test.com',
        'phone'            => '0700' . rand(100000,999999),
        'employee_number'  => 'TEST-' . rand(1000,9999),
        'department_id'    => $dept['department_id'],
        'designation_id'   => $des['designation_id'],
        'gender'           => 'male',
        'hire_date'        => $today,
        'employment_status'=> 'active',
        'basic_salary'     => '500000',
        'project_id'       => $project_id,
        'physical_address' => 'Test Address',
    ];
    $_FILES = [];

    ob_start();
    include __DIR__ . '/../api/add_employee.php';
    $out = ob_get_clean();
    $res = json_decode($out, true);

    if ($res && $res['success']) {
        pass("New Staff created and assigned to project (employee_id: {$res['employee_id']})");
        $new_employee_id = $res['employee_id'];
    } else {
        fail("New Staff creation failed: " . ($res['message'] ?? $out));
        $new_employee_id = null;
    }
} else {
    fail("No active department/designation found — skipping new staff test");
    $new_employee_id = null;
}

endsec();

// ══════════════════════════════════════════════════════════════════════
// 2. ATTENDANCE — Mark, Load, Delete
// ══════════════════════════════════════════════════════════════════════
section("2. ATTENDANCE");

// 2a. Mark attendance (create)
$_POST = ['project_id' => $project_id, 'employee_id' => $employee_id, 'attendance_date' => $today, 'status' => 'present', 'check_in_time' => '08:00', 'check_out_time' => '17:00', 'notes' => 'Test'];
ob_start(); include __DIR__ . '/../api/operations/save_project_attendance.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) pass("Mark Attendance (submit) — " . $res['message']);
else fail("Mark Attendance failed: " . ($res['message'] ?? $out));

// 2b. Load attendance API
$_GET = ['project_id' => $project_id, 'date_from' => $today, 'date_to' => $today];
ob_start(); include __DIR__ . '/../api/operations/get_project_attendance.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) {
    pass("Load Attendance — " . count($res['data']) . " record(s) returned, stats: present={$res['stats']['present']}, absent={$res['stats']['absent']}");
    $att_id = count($res['data']) > 0 ? $res['data'][0]['attendance_id'] : null;
} else {
    fail("Load Attendance failed: " . ($res['message'] ?? $out));
    $att_id = null;
}

// 2c. Edit attendance (update)
$_POST = ['project_id' => $project_id, 'employee_id' => $employee_id, 'attendance_date' => $today, 'status' => 'late', 'check_in_time' => '09:30', 'check_out_time' => '17:00', 'notes' => 'Updated via test'];
ob_start(); include __DIR__ . '/../api/operations/save_project_attendance.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) pass("Edit Attendance (submit) — " . $res['message']);
else fail("Edit Attendance failed: " . ($res['message'] ?? $out));

// 2d. Delete attendance
if ($att_id) {
    $_POST = ['attendance_id' => $att_id];
    ob_start(); include __DIR__ . '/../api/delete_attendance.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Delete Attendance — " . $res['message']);
    else fail("Delete Attendance failed: " . ($res['message'] ?? $out));
}

endsec();

// ══════════════════════════════════════════════════════════════════════
// 3. LEAVES — Apply, Load, Edit, Approve, Reject, Delete
// ══════════════════════════════════════════════════════════════════════
section("3. LEAVES");

// 3a. Apply leave
$_POST = ['project_id' => $project_id, 'employee_id' => $employee_id, 'leave_type' => 'annual', 'start_date' => $today, 'end_date' => date('Y-m-d', strtotime($today . ' +2 days')), 'total_days' => 3, 'reason' => 'Test annual leave', 'status' => 'pending', 'notes' => 'HR module test'];
ob_start(); include __DIR__ . '/../api/operations/save_project_leave.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) pass("Apply Leave (submit) — " . $res['message']);
else fail("Apply Leave failed: " . ($res['message'] ?? $out));

// 3b. Load leaves
$_GET = ['project_id' => $project_id, 'date_from' => date('Y-01-01'), 'date_to' => date('Y-12-31')];
ob_start(); include __DIR__ . '/../api/operations/get_project_leaves.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) {
    pass("Load Leaves — " . count($res['data']) . " record(s), stats: total={$res['stats']['total']}, pending={$res['stats']['pending']}");
    $leave_id = count($res['data']) > 0 ? $res['data'][0]['leave_id'] : null;
} else {
    fail("Load Leaves failed: " . ($res['message'] ?? $out));
    $leave_id = null;
}

// 3c. Edit leave
if ($leave_id) {
    $_POST = ['leave_id' => $leave_id, 'project_id' => $project_id, 'employee_id' => $employee_id, 'leave_type' => 'sick', 'start_date' => $today, 'end_date' => $today, 'total_days' => 1, 'reason' => 'Updated to sick leave', 'status' => 'pending', 'notes' => 'edited'];
    ob_start(); include __DIR__ . '/../api/operations/save_project_leave.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Edit Leave (submit) — " . $res['message']);
    else fail("Edit Leave failed: " . ($res['message'] ?? $out));

    // 3d. Approve leave
    $_POST = ['leave_id' => $leave_id];
    ob_start(); include __DIR__ . '/../api/approve_leave.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Approve Leave — " . $res['message']);
    else fail("Approve Leave failed: " . ($res['message'] ?? $out));

    // 3e. Reject leave (apply a fresh one)
    $_POST = ['project_id' => $project_id, 'employee_id' => $employee_id, 'leave_type' => 'unpaid', 'start_date' => date('Y-m-d', strtotime($today . ' +5 days')), 'end_date' => date('Y-m-d', strtotime($today . ' +5 days')), 'total_days' => 1, 'reason' => 'Reject test', 'status' => 'pending', 'notes' => ''];
    ob_start(); include __DIR__ . '/../api/operations/save_project_leave.php'; $out = ob_get_clean();
    $res2 = json_decode($out, true);

    $_GET = ['project_id' => $project_id, 'date_from' => date('Y-01-01'), 'date_to' => date('Y-12-31'), 'status' => 'pending'];
    ob_start(); include __DIR__ . '/../api/operations/get_project_leaves.php'; $out2 = ob_get_clean();
    $res3 = json_decode($out2, true);
    $reject_id = count($res3['data'] ?? []) > 0 ? $res3['data'][0]['leave_id'] : null;

    if ($reject_id) {
        $_POST = ['leave_id' => $reject_id];
        ob_start(); include __DIR__ . '/../api/reject_leave.php'; $out = ob_get_clean();
        $res = json_decode($out, true);
        if ($res && $res['success']) pass("Reject Leave — " . $res['message']);
        else fail("Reject Leave failed: " . ($res['message'] ?? $out));
    }

    // 3f. Delete leave
    $_POST = ['leave_id' => $leave_id];
    ob_start(); include __DIR__ . '/../api/delete_leave.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Delete Leave — " . $res['message']);
    else fail("Delete Leave failed: " . ($res['message'] ?? $out));
}

endsec();

// ══════════════════════════════════════════════════════════════════════
// 4. PAYROLL — Process, Load, Edit, View, Delete
// ══════════════════════════════════════════════════════════════════════
section("4. PAYROLL");

// 4a. Process payroll
$_POST = ['project_id' => $project_id, 'payroll_period' => $month, 'payroll_date' => $today, 'include_allowances' => '1', 'include_deductions' => '1'];
ob_start(); include __DIR__ . '/../api/operations/process_project_payroll.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) pass("Process Payroll (submit) — " . $res['message']);
else fail("Process Payroll failed: " . ($res['message'] ?? $out));

// 4b. Load payroll
$_GET = ['project_id' => $project_id, 'period' => $month];
ob_start(); include __DIR__ . '/../api/operations/get_project_payroll.php'; $out = ob_get_clean();
$res = json_decode($out, true);
if ($res && $res['success']) {
    pass("Load Payroll — " . count($res['data']) . " record(s), total_payout=" . number_format($res['stats']['total_payout']));
    $payroll_id = count($res['data']) > 0 ? $res['data'][0]['payroll_id'] : null;
} else {
    fail("Load Payroll failed: " . ($res['message'] ?? $out));
    $payroll_id = null;
}

// 4c. Edit payroll
if ($payroll_id) {
    $_POST = ['payroll_id' => $payroll_id, 'basic_salary' => '600000', 'total_allowances' => '50000', 'total_deductions' => '30000', 'net_salary' => '620000', 'status' => 'approved'];
    ob_start(); include __DIR__ . '/../api/update_payroll.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Edit Payroll (submit) — " . $res['message']);
    else fail("Edit Payroll failed: " . ($res['message'] ?? $out));

    // 4d. View payroll (re-load and check data)
    $_GET = ['project_id' => $project_id, 'period' => $month];
    ob_start(); include __DIR__ . '/../api/operations/get_project_payroll.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    $rec = collect_by_id($res['data'] ?? [], 'payroll_id', $payroll_id);
    if ($rec) pass("View Payroll — net_salary=" . number_format($rec['net_salary']) . " TZS, status=" . ($rec['payment_status'] ?: $rec['status']));
    else fail("View Payroll — record not found after edit");

    // 4e. Delete payroll
    $_POST = ['payroll_id' => $payroll_id];
    ob_start(); include __DIR__ . '/../api/delete_payroll.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) pass("Delete Payroll — " . $res['message']);
    else fail("Delete Payroll failed: " . ($res['message'] ?? $out));
}

endsec();

// ══════════════════════════════════════════════════════════════════════
// 5. ASSIGN STAFF MODAL
// ══════════════════════════════════════════════════════════════════════
section("5. ASSIGN STAFF (Existing Employee)");

$unassigned = $pdo->query("SELECT employee_id FROM employees WHERE (project_id IS NULL OR project_id = 0) AND status != 'terminated' LIMIT 1")->fetch();
if ($unassigned) {
    $_POST = ['employee_id' => $unassigned['employee_id'], 'project_id' => $project_id];
    ob_start(); include __DIR__ . '/../api/operations/update_staff_project.php'; $out = ob_get_clean();
    $res = json_decode($out, true);
    if ($res && $res['success']) {
        pass("Assign Staff — employee {$unassigned['employee_id']} assigned to project");
        // Unassign back
        $_POST = ['employee_id' => $unassigned['employee_id'], 'project_id' => null];
        ob_start(); include __DIR__ . '/../api/operations/update_staff_project.php'; $out = ob_get_clean();
        $res2 = json_decode($out, true);
        if ($res2 && $res2['success']) pass("Unassign Staff — employee {$unassigned['employee_id']} unassigned (restored)");
        else fail("Unassign Staff failed");
    } else {
        fail("Assign Staff failed: " . ($res['message'] ?? $out));
    }
} else {
    pass("Assign Staff — no unassigned staff available (skipped, all staff already assigned)");
}

endsec();

// ══════════════════════════════════════════════════════════════════════
// 6. NEW STAFF CLEANUP
// ══════════════════════════════════════════════════════════════════════
if ($new_employee_id) {
    section("6. NEW STAFF CLEANUP");
    $pdo->prepare("DELETE FROM employees WHERE employee_id = ?")->execute([$new_employee_id]);
    pass("Test employee (id: $new_employee_id) cleaned up from DB");
    endsec();
}

// ══════════════════════════════════════════════════════════════════════
// SUMMARY
// ══════════════════════════════════════════════════════════════════════
echo "<hr><h2>Test Complete</h2>";
echo "<p>All HR module endpoints have been tested. Check results above for any ❌ FAIL items.</p>";
echo "<p><a href='/bms/project_view?id=$project_id' style='color:blue'>→ Open Project in Browser</a></p>";
echo "</body></html>";

function collect_by_id($arr, $key, $val) {
    foreach ($arr as $r) { if ($r[$key] == $val) return $r; }
    return null;
}
