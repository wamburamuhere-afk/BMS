<?php
/**
 * HR Lifecycle (Tier 1, Phase 1.3) workflow CLI test.
 *   php tests/test_hr_lifecycle_workflow_cli.php
 *
 * Drives change/delete/download lifecycle APIs in isolated subprocesses. Proves:
 * approval applies the D4 effect atomically (promotion/transfer/termination),
 * a future-dated resignation defers (D5) and the catch-up applies it exactly
 * once, reject/cancel apply no effect, creator-cannot-approve, approved
 * history is immutable (no delete, no double-approve), and the legacy
 * api/update_employee_status.php path still works unchanged (D6 regression).
 */
$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST  = $cfg['post']  ?? [];
    $_GET   = $cfg['get']   ?? [];
    $_FILES = $cfg['files'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}
require_once "$root/roots.php";
require_once "$root/core/lifecycle_effects.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$ADMIN = ['user_id' => 1, 'username' => 'testadmin', 'is_admin' => true, 'role_id' => 1];

function call($ep, $payload, $session = null, $method = 'POST') {
    global $root, $ADMIN;
    $cfg = ['session' => $session ?? $ADMIN, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'hrw');
    file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true);
}
function addEvent($type, $emp_id, $extra = [], $session = null) {
    return call('add_lifecycle_event', array_merge([
        'employee_id' => $emp_id, 'event_type' => $type, 'event_date' => date('Y-m-d'),
        'title' => "WF test $type",
    ], $extra), $session);
}
function evRow(PDO $pdo, int $id) {
    return $pdo->query("SELECT * FROM employee_lifecycle_events WHERE event_id = $id")->fetch(PDO::FETCH_ASSOC);
}
function empRow(PDO $pdo, int $id) {
    return $pdo->query("SELECT * FROM employees WHERE employee_id = $id")->fetch(PDO::FETCH_ASSOC);
}

$emp_id = 0; $made_project = 0;
try {
    // ── Fixtures: employee + a second designation/department to move into ───
    $desigs = $pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $depts  = $pdo->query("SELECT department_id, department_name FROM departments WHERE status='active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $proj_id = (int)$pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
    if (!$proj_id) {
        $pdo->exec("INSERT INTO projects (project_name, status) VALUES ('__wf_test_project', 'active')");
        $proj_id = $made_project = (int)$pdo->lastInsertId();
    }
    $d1 = (int)($desigs[0] ?? 0); $d2 = (int)($desigs[1] ?? $desigs[0] ?? 0);
    $dep1 = $depts[0] ?? null; $dep2 = $depts[1] ?? $depts[0] ?? null;
    ok($d1 && $dep1 && $proj_id, "have designations, departments, project fixtures");

    $pdo->prepare("INSERT INTO employees (first_name, last_name, employment_status, designation_id, department_id, department, basic_salary, project_id, created_at)
                   VALUES ('__WF', 'Fixture', 'active', ?, ?, ?, 500000, ?, NOW())")
        ->execute([$d1, (int)$dep1['department_id'], $dep1['department_name'], $proj_id]);
    $emp_id = (int)$pdo->lastInsertId();
    ok($emp_id > 0, "fixture employee created (#$emp_id)");

    $CREATOR = [
        'user_id' => 777001, 'username' => 'wf_creator', 'is_admin' => false, 'role_id' => 4,
        'permissions' => ['employee_lifecycle' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'review' => true, 'approve' => true]],
        'scope' => ['is_admin' => false, 'projects' => [$proj_id], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()],
    ];
    $APPROVER = $CREATOR; $APPROVER['user_id'] = 777002; $APPROVER['username'] = 'wf_approver';
    $NOBODY   = $CREATOR; $NOBODY['user_id'] = 777003; $NOBODY['username'] = 'wf_nobody';
    $NOBODY['permissions']['employee_lifecycle'] = ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'review' => false, 'approve' => false];

    // ── 1. Approve promotion → designation + salary applied, stamped ────────
    $r = addEvent('promotion', $emp_id, ['new_designation_id' => $d2, 'new_salary' => 900000], $CREATOR);
    $promo = (int)($r['event_id'] ?? 0);
    ok($promo, "creator (non-admin) recorded promotion #$promo");

    $r = call('change_lifecycle_status', ['event_id' => $promo, 'action' => 'approve'], $CREATOR);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'yourself') !== false, "creator cannot approve their own event (segregation of duties)");

    $r = call('change_lifecycle_status', ['event_id' => $promo, 'action' => 'approve'], $APPROVER);
    ok(!empty($r['success']), "different approver can approve" . (empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));
    $e = empRow($pdo, $emp_id); $v = evRow($pdo, $promo);
    ok((int)$e['designation_id'] === $d2 && (float)$e['basic_salary'] === 900000.0, "promotion applied: designation + salary changed");
    ok($v['status'] === 'approved' && $v['effect_applied_at'] !== null && (int)$v['approved_by'] === 777002, "event stamped approved + effect_applied_at + approved_by");
    $audits = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='employee' AND entity_id=$emp_id AND action='update_status'")->fetchColumn();
    ok((int)$audits >= 1, "employee-row audit written in the legacy endpoint's shape");

    $r = call('change_lifecycle_status', ['event_id' => $promo, 'action' => 'approve'], $ADMIN);
    ok(empty($r['success']), "double-approve blocked (approved is terminal)");

    // ── 2. Approve transfer → department id + legacy varchar + project ──────
    $r = addEvent('transfer', $emp_id, ['new_department_id' => (int)$dep2['department_id'], 'new_project_id' => $proj_id], $CREATOR);
    $tr = (int)($r['event_id'] ?? 0);
    $r = call('change_lifecycle_status', ['event_id' => $tr, 'action' => 'approve'], $APPROVER);
    $e = empRow($pdo, $emp_id);
    ok(!empty($r['success']) && (int)$e['department_id'] === (int)$dep2['department_id']
        && $e['department'] === $dep2['department_name'],
        "transfer applied: department_id + legacy department varchar kept in sync");

    // ── 3. Reject applies no effect ──────────────────────────────────────────
    $r = addEvent('promotion', $emp_id, ['new_designation_id' => $d1, 'new_salary' => 1], $CREATOR);
    $rej = (int)($r['event_id'] ?? 0);
    $r = call('change_lifecycle_status', ['event_id' => $rej, 'action' => 'reject'], $APPROVER);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'reason') !== false, "reject without a reason refused");
    $r = call('change_lifecycle_status', ['event_id' => $rej, 'action' => 'reject', 'reject_reason' => 'Not justified'], $APPROVER);
    $e = empRow($pdo, $emp_id); $v = evRow($pdo, $rej);
    ok(!empty($r['success']) && $v['status'] === 'rejected' && $v['reject_reason'] === 'Not justified'
        && (float)$e['basic_salary'] === 900000.0 && $v['effect_applied_at'] === null,
        "reject recorded with reason; employee untouched");

    // ── 4. Cancel: creator yes, stranger no ──────────────────────────────────
    $r = addEvent('award', $emp_id, ['award_type' => 'Test'], $CREATOR);
    $aw = (int)($r['event_id'] ?? 0);
    $r = call('change_lifecycle_status', ['event_id' => $aw, 'action' => 'cancel'], $NOBODY);
    ok(empty($r['success']), "non-creator without edit rights cannot cancel");
    $r = call('change_lifecycle_status', ['event_id' => $aw, 'action' => 'cancel'], $CREATOR);
    ok(!empty($r['success']) && evRow($pdo, $aw)['status'] === 'cancelled', "creator can cancel their own pending event");

    // ── 5. Record-only approval leaves employee untouched ────────────────────
    $r = addEvent('warning', $emp_id, ['severity' => 'final'], $CREATOR);
    $wr = (int)($r['event_id'] ?? 0);
    $before = empRow($pdo, $emp_id);
    $r = call('change_lifecycle_status', ['event_id' => $wr, 'action' => 'approve'], $APPROVER);
    $after = empRow($pdo, $emp_id); $v = evRow($pdo, $wr);
    ok(!empty($r['success']) && $v['status'] === 'approved' && $v['effect_applied_at'] === null
        && $before['employment_status'] === $after['employment_status']
        && $before['designation_id'] === $after['designation_id'],
        "warning approved as record-only: no employee change, no effect stamp");

    // ── 6. Future resignation defers (D5), catch-up applies exactly once ─────
    $future = date('Y-m-d', strtotime('+10 days'));
    $r = addEvent('resignation', $emp_id, ['end_date' => $future], $CREATOR);
    $res = (int)($r['event_id'] ?? 0);
    $r = call('change_lifecycle_status', ['event_id' => $res, 'action' => 'approve'], $APPROVER);
    $e = empRow($pdo, $emp_id); $v = evRow($pdo, $res);
    ok(!empty($r['success']) && $v['status'] === 'approved' && $v['effect_applied_at'] === null
        && $e['employment_status'] === 'active',
        "future resignation approved but NOT applied — employee stays active through notice");

    $n = applyDueLifecycleEffects($pdo);
    ok($n === 0 && empRow($pdo, $emp_id)['employment_status'] === 'active', "catch-up is a no-op while the date is still future");

    // Simulate the last working day passing
    $pdo->exec("UPDATE employee_lifecycle_events SET event_date = DATE_SUB(CURDATE(), INTERVAL 20 DAY), end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE event_id = $res");
    $n = applyDueLifecycleEffects($pdo);
    $e = empRow($pdo, $emp_id); $v = evRow($pdo, $res);
    ok($n === 1 && $e['employment_status'] === 'resigned' && $v['effect_applied_at'] !== null,
        "catch-up applies the resignation once the date passes");
    $n = applyDueLifecycleEffects($pdo);
    ok($n === 0, "catch-up is idempotent (second run applies nothing)");

    // ── 7. Termination applies on approval ───────────────────────────────────
    $pdo->exec("UPDATE employees SET employment_status = 'active' WHERE employee_id = $emp_id");
    $r = addEvent('termination', $emp_id, ['termination_type' => 'redundancy'], $CREATOR);
    $term = (int)($r['event_id'] ?? 0);
    $r = call('change_lifecycle_status', ['event_id' => $term, 'action' => 'approve'], $APPROVER);
    ok(!empty($r['success']) && empRow($pdo, $emp_id)['employment_status'] === 'terminated',
        "termination approval flips employment_status to terminated");

    // ── 8. Delete rules: approved immutable, pending deletable ──────────────
    $r = call('delete_lifecycle_event', ['event_id' => $term], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'permanent') !== false, "approved event cannot be deleted (immutable history)");
    $r = addEvent('award', $emp_id, ['award_type' => 'Del test'], $CREATOR);
    $del = (int)($r['event_id'] ?? 0);
    $r = call('delete_lifecycle_event', ['event_id' => $del], $NOBODY);
    ok(empty($r['success']), "delete denied without canDelete");
    $r = call('delete_lifecycle_event', ['event_id' => $del], $ADMIN);
    ok(!empty($r['success']) && evRow($pdo, $del)['status'] === 'deleted', "pending event soft-deleted by canDelete holder");

    // ── 9. Download gatekeeper ────────────────────────────────────────────────
    $r = call('download_lifecycle_attachment', ['event_id' => $promo], null, 'GET');
    ok(is_array($r) && (($r['_raw'] ?? '') !== '') && stripos($r['_raw'] ?? '', 'not found') !== false || ($r === null),
        "download of event without attachment 404s cleanly");
    $noScope = $CREATOR; $noScope['user_id'] = 777009; $noScope['scope']['projects'] = [];
    $r = call('download_lifecycle_attachment', ['event_id' => $promo], $noScope, 'GET');
    ok(stripos(json_encode($r), 'scope') !== false, "download denied for out-of-scope user before any file check");

    // ── 10. D6 regression — legacy update_employee_status.php untouched ─────
    $r = call('update_employee_status', ['employee_id' => $emp_id, 'status' => 'on_leave'], $ADMIN);
    ok(!empty($r['success']) && empRow($pdo, $emp_id)['employment_status'] === 'on_leave',
        "legacy api/update_employee_status.php still flips status directly (D6)");

    // ── 11. Scope denial on the workflow API ─────────────────────────────────
    $r = addEvent('award', $emp_id, ['award_type' => 'Scope test'], $CREATOR);
    $sc = (int)($r['event_id'] ?? 0);
    $noScope = $APPROVER; $noScope['user_id'] = 777010; $noScope['scope']['projects'] = [];
    $r = call('change_lifecycle_status', ['event_id' => $sc, 'action' => 'approve'], $noScope);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'scope') !== false, "approve denied for out-of-scope event (non-admin)");
    $r = call('change_lifecycle_status', ['event_id' => $sc, 'action' => 'approve'], $NOBODY);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'permission') !== false, "approve denied without canApprove");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($emp_id) {
        $pdo->exec("DELETE FROM employee_lifecycle_events WHERE employee_id = $emp_id");
        // Tier 4 D28(c): approving a termination auto-spawns an offboarding
        // checklist — remove any so the FK on employees does not block cleanup.
        foreach ($pdo->query("SELECT checklist_id FROM employee_checklists WHERE employee_id = $emp_id")->fetchAll(PDO::FETCH_COLUMN) as $__cid) {
            $pdo->exec("DELETE FROM employee_checklist_items WHERE checklist_id = " . (int)$__cid);
        }
        $pdo->exec("DELETE FROM employee_checklists WHERE employee_id = $emp_id");
        $pdo->exec("DELETE FROM employees WHERE employee_id = $emp_id");
        $pdo->exec("DELETE FROM audit_logs WHERE entity_type='employee' AND entity_id = $emp_id");
    }
    if ($made_project) {
        $pdo->exec("DELETE FROM projects WHERE project_id = $made_project");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
