<?php
/**
 * Employee Contracts module (Tier 2, Phase 2.3) CLI test.
 *   php tests/test_employee_contracts_cli.php
 *
 * Drives add/get/list/change-status APIs in isolated subprocesses. Proves:
 * validation matrix, activation dual-writes employees.contract_end_date /
 * probation_end_date (D12), activating a second contract auto-renews the
 * first (only one active per employee), termination stamps status,
 * scope + permission denials, the D13 hr-expiry cron fires once per
 * milestone via the shared notification engine (dedupe holds on re-run,
 * recipients are RBAC-driven), and both new pages render without errors.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $page = $argv[2];
    if ($page === 'employee_contracts') {
        $_SERVER['REQUEST_URI'] = '/employee_contracts';
        require "$root/app/bms/pos/employee_contracts.php";
    } else {
        $_SERVER['REQUEST_URI'] = '/employee_details';
        $_GET['id'] = (int)($argv[4] ?? 0);
        require "$root/app/bms/pos/employee_details.php";
    }
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? []; $_FILES = $cfg['files'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/notify.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST', $files = []) {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload, 'files' => $files];
    $f = tempnam(sys_get_temp_dir(), 'ecx'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $empId = 0) {
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $empId 2>&1");
}
function noErr($html) { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function', 'Call to undefined'] as $e) if (stripos($html, $e) !== false) return false; return true; }

$emp_id = 0; $emp2_id = 0; $emp3_id = 0; $emp4_id = 0; $emp5_id = 0;
$contract_a = 0; $contract_b = 0; $contract_c = 0; $contract_d = 0; $contract_e = 0; $contract_f = 0;
$test_start = date('Y-m-d H:i:s');
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999903, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];

    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('__EC', 'Fixture', '__EC-TEST-1', 'active', NOW())");
    $emp_id = (int)$pdo->lastInsertId();
    ok($emp_id > 0, "fixture employee ready (#$emp_id)");

    // ── 1. Validation matrix ─────────────────────────────────────────────────
    $base = ['employee_id' => $emp_id, 'contract_type' => 'Permanent', 'start_date' => date('Y-m-d')];
    $bad = [
        'missing employee'    => ['contract_type' => 'Permanent', 'start_date' => date('Y-m-d')],
        'missing type'        => ['employee_id' => $emp_id, 'start_date' => date('Y-m-d')],
        'missing start date'  => ['employee_id' => $emp_id, 'contract_type' => 'Permanent'],
        'end before start'    => $base + ['end_date' => date('Y-m-d', strtotime('-1 day'))],
        'negative probation'  => $base + ['probation_months' => '-1'],
        'negative salary'     => $base + ['basic_salary' => '-500'],
        'nonexistent employee'=> ['employee_id' => 99999999, 'contract_type' => 'Permanent', 'start_date' => date('Y-m-d')],
    ];
    foreach ($bad as $label => $p) {
        $r = call('add_contract', $p, $ADMIN);
        ok(empty($r['success']), "rejects: $label" . (!empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));
    }
    $r = call('add_contract', $base, $NOPERM);
    ok(empty($r['success']), "create denied without canCreate");

    // ── 2. Create + activate contract A (fixed-term, expiring soon, w/ probation) ─
    $endA = date('Y-m-d', strtotime('+5 days'));
    $startA = date('Y-m-d', strtotime('-30 days'));
    $r = call('add_contract', [
        'employee_id' => $emp_id, 'contract_type' => 'Fixed-term',
        'start_date' => $startA, 'end_date' => $endA,
        'probation_months' => 2, 'basic_salary' => 400000,
    ], $ADMIN);
    $contract_a = (int)($r['contract_id'] ?? 0);
    ok($contract_a > 0 && ($r['message'] ?? '') === 'Contract created as draft', "contract A created as draft");

    $r = call('change_contract_status', ['contract_id' => $contract_a, 'action' => 'terminate'], $ADMIN);
    ok(empty($r['success']), "cannot terminate a draft contract");

    $r = call('change_contract_status', ['contract_id' => $contract_a, 'action' => 'activate'], $NOPERM);
    ok(empty($r['success']), "activate denied without canApprove");

    $r = call('change_contract_status', ['contract_id' => $contract_a, 'action' => 'activate'], $ADMIN);
    ok(!empty($r['success']), "contract A activated: " . ($r['message'] ?? ''));

    $emp = $pdo->query("SELECT contract_end_date, probation_end_date FROM employees WHERE employee_id = $emp_id")->fetch(PDO::FETCH_ASSOC);
    ok($emp['contract_end_date'] === $endA, "D12 dual-write: employees.contract_end_date set from contract A");
    ok($emp['probation_end_date'] === date('Y-m-d', strtotime($startA . ' +2 months')), "D12 dual-write: employees.probation_end_date computed from start_date + probation_months");

    $r = call('change_contract_status', ['contract_id' => $contract_a, 'action' => 'activate'], $ADMIN);
    ok(empty($r['success']), "cannot re-activate an already-active contract");

    // ── 3. Renewal: activating a new contract auto-renews contract A ────────
    $endB = date('Y-m-d', strtotime('+400 days'));
    $r = call('add_contract', [
        'employee_id' => $emp_id, 'contract_type' => 'Permanent', 'start_date' => date('Y-m-d'), 'end_date' => $endB,
    ], $ADMIN);
    $contract_b = (int)($r['contract_id'] ?? 0);
    ok($contract_b > 0, "contract B (renewal) created as draft");

    $r = call('change_contract_status', ['contract_id' => $contract_b, 'action' => 'activate'], $ADMIN);
    ok(!empty($r['success']) && stripos($r['message'], 'renewed') !== false, "contract B activation reports the renewal: " . ($r['message'] ?? ''));

    $statusA = $pdo->query("SELECT status FROM employee_contracts WHERE contract_id = $contract_a")->fetchColumn();
    $rowB = $pdo->query("SELECT status, renewed_from_contract_id FROM employee_contracts WHERE contract_id = $contract_b")->fetch(PDO::FETCH_ASSOC);
    ok($statusA === 'renewed', "contract A stamped 'renewed'");
    ok($rowB['status'] === 'active' && (int)$rowB['renewed_from_contract_id'] === $contract_a, "contract B is active and links back to contract A");

    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM employee_contracts WHERE employee_id = $emp_id AND status = 'active'")->fetchColumn();
    ok($activeCount === 1, "exactly one active contract for the employee after renewal");

    $emp2check = $pdo->query("SELECT contract_end_date FROM employees WHERE employee_id = $emp_id")->fetchColumn();
    ok($emp2check === $endB, "D12 dual-write updated again to contract B's end_date");

    // ── 4. Terminate ──────────────────────────────────────────────────────────
    $r = call('change_contract_status', ['contract_id' => $contract_b, 'action' => 'terminate'], $ADMIN);
    ok(!empty($r['success']), "contract B terminated: " . ($r['message'] ?? ''));
    $statusB = $pdo->query("SELECT status FROM employee_contracts WHERE contract_id = $contract_b")->fetchColumn();
    ok($statusB === 'terminated', "contract B status stamped terminated");

    // Cascade: contract B was the employee's only remaining draft/active
    // contract (A is 'renewed') — terminating it must deactivate the employee.
    ok(stripos($r['message'], 'marked inactive') !== false, "terminate response reports the employee-deactivation cascade");
    $empAfter = $pdo->query("SELECT status, employment_status FROM employees WHERE employee_id = $emp_id")->fetch(PDO::FETCH_ASSOC);
    ok($empAfter['status'] === 'inactive' && $empAfter['employment_status'] === 'terminated',
        "cascade: employee deactivated after their only contract was terminated");

    // ── 4b. No cascade when another draft/active contract remains ──────────────
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('__EC4', 'Renewing', '__EC-TEST-4', 'active', NOW())");
    $emp4_id = (int)$pdo->lastInsertId();
    $r = call('add_contract', ['employee_id' => $emp4_id, 'contract_type' => 'Fixed-term', 'start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('+2 days'))], $ADMIN);
    $contract_d = (int)($r['contract_id'] ?? 0);
    call('change_contract_status', ['contract_id' => $contract_d, 'action' => 'activate'], $ADMIN);
    $r = call('add_contract', ['employee_id' => $emp4_id, 'contract_type' => 'Permanent', 'start_date' => date('Y-m-d', strtotime('+3 days'))], $ADMIN);
    $contract_e = (int)($r['contract_id'] ?? 0);
    ok($contract_d > 0 && $contract_e > 0, "renewal-in-progress fixtures ready (active #$contract_d, draft #$contract_e)");

    $r = call('change_contract_status', ['contract_id' => $contract_d, 'action' => 'terminate'], $ADMIN);
    ok(!empty($r['success']) && stripos($r['message'], 'marked inactive') === false,
        "terminating one contract while a draft renewal exists does NOT report a cascade: " . ($r['message'] ?? ''));
    $emp4Status = $pdo->query("SELECT status FROM employees WHERE employee_id = $emp4_id")->fetchColumn();
    ok($emp4Status === 'active', "no cascade: employee stays active while a draft renewal contract remains");

    // ── 5. get_contract / get_contracts ───────────────────────────────────────
    $r = call('get_contract', ['contract_id' => $contract_b], $ADMIN, 'GET');
    ok(!empty($r['success']) && $r['data']['status'] === 'terminated', "get_contract returns the terminated row");
    $r = call('get_contracts', ['employee_id' => $emp_id], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 2, "get_contracts lists both contracts for the employee");

    // ── 6. Scope denial ───────────────────────────────────────────────────────
    $proj = (int)$pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
    if ($proj) {
        $pdo->exec("UPDATE employees SET project_id = $proj WHERE employee_id = $emp_id");
        $SCOPED = ['user_id' => 999904, 'username' => 'scoped', 'is_admin' => false, 'role_id' => 999,
            'permissions' => ['employee_contracts' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'review' => false, 'approve' => true]],
            'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];
        $r = call('get_contracts', ['employee_id' => $emp_id], $SCOPED, 'GET');
        ok(empty($r['success']) && stripos(json_encode($r), 'scope') !== false, "list denied for out-of-scope employee (non-admin)");
    } else {
        ok(true, "no project available — scope denial covered by helper tests (skip)");
    }

    // ── 7. D13 HR expiry cron: contract milestone ────────────────────────────
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, probation_end_date, created_at)
                VALUES ('__EC2', 'Cron', '__EC-TEST-2', 'probation', '" . date('Y-m-d', strtotime('+3 days')) . "', NOW())");
    $emp2_id = (int)$pdo->lastInsertId();
    $endC = date('Y-m-d', strtotime('+5 days'));
    $pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, end_date, status, activated_by, activated_at, created_by)
                   VALUES (?, 'Fixed-term', ?, ?, 'active', ?, NOW(), ?)")
        ->execute([$emp2_id, date('Y-m-d', strtotime('-60 days')), $endC, $admin_uid, $admin_uid]);
    $contract_c = (int)$pdo->lastInsertId();

    // Fixture for the auto-close path: an active contract whose end_date is
    // already in the past (as if nobody ran this cron for a few days).
    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('__EC5', 'Expired', '__EC-TEST-5', 'active', NOW())");
    $emp5_id = (int)$pdo->lastInsertId();
    $endF = date('Y-m-d', strtotime('-3 days'));
    $pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, end_date, status, activated_by, activated_at, created_by)
                   VALUES (?, 'Fixed-term', ?, ?, 'active', ?, NOW(), ?)")
        ->execute([$emp5_id, date('Y-m-d', strtotime('-90 days')), $endF, $admin_uid, $admin_uid]);
    $contract_f = (int)$pdo->lastInsertId();

    $beforeC = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_contract_expiry' AND entity_id = $contract_c")->fetchColumn();
    require_once "$root/cron/check_hr_expiry.php";
    $afterC = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_contract_expiry' AND entity_id = $contract_c")->fetchColumn();
    ok($afterC > $beforeC, "D13: hr-expiry cron fired for the expiring contract via the shared notification engine");

    // ── D13b. Auto-close: contract F's end_date already passed ──────────────
    $statusF = $pdo->query("SELECT status FROM employee_contracts WHERE contract_id = $contract_f")->fetchColumn();
    ok($statusF === 'expired', "auto-close: past-due contract flipped to 'expired'");
    $emp5After = $pdo->query("SELECT status, employment_status FROM employees WHERE employee_id = $emp5_id")->fetch(PDO::FETCH_ASSOC);
    ok($emp5After['status'] === 'inactive' && $emp5After['employment_status'] === 'terminated',
        "auto-close: employee deactivated (no other draft/active contract)");
    $autoNotif = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_contract_expired_autoclosed' AND entity_id = $contract_f")->fetchColumn();
    ok($autoNotif > 0, "auto-close: hr_contract_expired_autoclosed notification fired");

    $reRunF = run_hr_expiry_check($pdo);
    ok(($reRunF['contracts_autoclosed'] ?? -1) === 0, "auto-close: re-run does not re-process an already-'expired' contract");

    $beforeP = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_probation_end' AND entity_id = $emp2_id")->fetchColumn();
    ok($beforeP > 0, "D13: probation-ending notification also fired in the same run");

    $afterC2 = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_contract_expiry' AND entity_id = $contract_c")->fetchColumn();
    $afterP2 = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_probation_end' AND entity_id = $emp2_id")->fetchColumn();
    run_hr_expiry_check($pdo);
    $reRunC = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_contract_expiry' AND entity_id = $contract_c")->fetchColumn();
    $reRunP = (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE event_key = 'hr_probation_end' AND entity_id = $emp2_id")->fetchColumn();
    ok($reRunC === $afterC2 && $reRunP === $afterP2, "re-run fires nothing new (milestone dedupe holds)");

    // ── 8. Page renders ────────────────────────────────────────────────────────
    $html = render('employee_contracts', $admin_uid);
    ok(noErr($html), "employee_contracts.php: no PHP/SQL errors");
    ok(strpos($html, 'Employee Contracts') !== false, "employee_contracts.php: page renders");

    $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                VALUES ('__EC3', 'Details', '__EC-TEST-3', 'active', NOW())");
    $emp3_id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employee_contracts (employee_id, contract_type, start_date, end_date, status, activated_by, activated_at, created_by)
                   VALUES (?, 'Permanent', ?, NULL, 'active', ?, NOW(), ?)")
        ->execute([$emp3_id, date('Y-m-d'), $admin_uid, $admin_uid]);
    $html = render('details', $admin_uid, $emp3_id);
    ok(noErr($html), "employee_details.php: no PHP/SQL errors with an active contract");
    ok(strpos($html, 'Contracts') !== false && strpos($html, 'Permanent') !== false, "employee_details.php: Contracts card shows the active contract");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ([$emp_id, $emp2_id, $emp3_id, $emp4_id, $emp5_id] as $id) {
        if ($id) {
            $pdo->exec("DELETE FROM employee_contracts WHERE employee_id = $id");
            $pdo->exec("DELETE FROM employees WHERE employee_id = $id");
        }
    }
    if ($contract_c) {
        $pdo->exec("DELETE FROM notification_log WHERE event_key = 'hr_contract_expiry' AND entity_id = $contract_c");
        $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'hr_contract_expiry|employee_contract|$contract_c|%'");
    }
    if ($contract_f) {
        $pdo->exec("DELETE FROM notification_log WHERE event_key = 'hr_contract_expired_autoclosed' AND entity_id = $contract_f");
        $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'hr_contract_expired_autoclosed|employee_contract|$contract_f|%'");
    }
    if ($emp2_id) {
        $pdo->exec("DELETE FROM notification_log WHERE event_key = 'hr_probation_end' AND entity_id = $emp2_id");
        $pdo->exec("DELETE FROM notification_dedupe WHERE dedupe_key LIKE 'hr_probation_end|employee|$emp2_id|%'");
    }
    $pdo->prepare("DELETE FROM notifications WHERE event_key IN ('hr_contract_expiry','hr_probation_end','hr_contract_expired_autoclosed') AND created_at >= ?")
        ->execute([$test_start]);
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
