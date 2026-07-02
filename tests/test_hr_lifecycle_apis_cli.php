<?php
/**
 * HR Lifecycle (Tier 1, Phase 1.2) core-APIs CLI test.
 *   php tests/test_hr_lifecycle_apis_cli.php
 *
 * Drives add/get/get-list lifecycle APIs in isolated subprocesses. Proves:
 * all 8 event types create as 'pending'; the per-type validation matrix
 * rejects bad payloads; old values are snapshotted SERVER-SIDE (forged client
 * "old" values ignored); list filters work; project-scope denial blocks a
 * non-admin from another project's employee. Fixtures cleaned in finally{}.
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
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$ADMIN_SESSION = ['user_id' => 1, 'username' => 'testadmin', 'is_admin' => true, 'role_id' => 1];

function call($ep, $payload, $session = null, $method = 'POST') {
    global $root, $ADMIN_SESSION;
    $cfg = [
        'session' => $session ?? $ADMIN_SESSION,
        'method'  => $method,
        ($method === 'GET' ? 'get' : 'post') => $payload,
    ];
    $f = tempnam(sys_get_temp_dir(), 'hrl');
    file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true);
}

$emp_id = 0; $made_project = 0;
try {
    // ── Fixtures ────────────────────────────────────────────────────────────
    $desig_id = (int)$pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 1")->fetchColumn();
    $dept_id  = (int)$pdo->query("SELECT department_id FROM departments WHERE status='active' LIMIT 1")->fetchColumn();
    $proj_id  = (int)$pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
    if (!$proj_id) {
        $pdo->exec("INSERT INTO projects (project_name, status) VALUES ('__lc_test_project', 'active')");
        $proj_id = (int)$pdo->lastInsertId();
        $made_project = $proj_id;
    }
    ok($desig_id && $dept_id && $proj_id, "have designation, department, project fixtures");

    $pdo->prepare("INSERT INTO employees (first_name, last_name, employment_status, designation_id, department_id, basic_salary, project_id, created_at)
                   VALUES ('__LC', 'Fixture', 'active', ?, ?, 500000, ?, NOW())")
        ->execute([$desig_id, $dept_id, $proj_id]);
    $emp_id = (int)$pdo->lastInsertId();
    ok($emp_id > 0, "fixture employee created (#$emp_id in project #$proj_id)");

    $today = date('Y-m-d');
    $future = date('Y-m-d', strtotime('+30 days'));

    // ── 1. Create all 8 event types ─────────────────────────────────────────
    $payloads = [
        'promotion'   => ['new_designation_id' => $desig_id, 'new_salary' => 750000],
        'demotion'    => ['new_designation_id' => $desig_id],
        'transfer'    => ['new_department_id' => $dept_id, 'new_project_id' => $proj_id],
        'award'       => ['award_type' => 'Employee of the Month', 'award_gift' => 'Certificate', 'award_amount' => 50000],
        'warning'     => ['severity' => 'written'],
        'complaint'   => ['complainant' => 'A colleague', 'resolution' => 'Mediation held'],
        'resignation' => ['end_date' => $future, 'notice_date' => $today],
        'termination' => ['termination_type' => 'misconduct'],
    ];
    $created = [];
    foreach ($payloads as $type => $extra) {
        $r = call('add_lifecycle_event', array_merge([
            'employee_id' => $emp_id, 'event_type' => $type, 'event_date' => $today,
            'title' => "Test $type", 'description' => "CLI test $type",
        ], $extra));
        $created[$type] = (int)($r['event_id'] ?? 0);
        ok(!empty($r['success']) && $created[$type], "create $type → pending event #{$created[$type]}"
            . (empty($r['success']) ? ' (' . json_encode($r) . ')' : ''));
    }
    $st = $pdo->query("SELECT COUNT(*) FROM employee_lifecycle_events WHERE employee_id = $emp_id AND status = 'pending'")->fetchColumn();
    ok((int)$st === 8, "all 8 rows stored with status=pending");

    // ── 2. Server-side snapshots (forged client old-values ignored) ─────────
    $r = call('add_lifecycle_event', [
        'employee_id' => $emp_id, 'event_type' => 'promotion', 'event_date' => $today,
        'title' => 'Forged old values test', 'new_designation_id' => $desig_id,
        'old_designation_id' => 99999, 'old_salary' => 1,          // forged — must be ignored
    ]);
    $forged_id = (int)($r['event_id'] ?? 0);
    $row = $pdo->query("SELECT old_designation_id, old_salary, old_department_id, old_project_id
                        FROM employee_lifecycle_events WHERE event_id = $forged_id")->fetch(PDO::FETCH_ASSOC);
    ok($row && (int)$row['old_designation_id'] === $desig_id && (float)$row['old_salary'] === 500000.0
        && (int)$row['old_department_id'] === $dept_id && (int)$row['old_project_id'] === $proj_id,
        "old values snapshotted server-side (forged POST values ignored)");

    // ── 3. Validation matrix ─────────────────────────────────────────────────
    $base = ['employee_id' => $emp_id, 'event_date' => $today, 'title' => 'x'];
    $bad = [
        'missing employee'                 => ['event_type' => 'award', 'event_date' => $today, 'title' => 'x'],
        'bad event_type'                   => $base + ['event_type' => 'party'],
        'missing title'                    => ['employee_id' => $emp_id, 'event_type' => 'award', 'event_date' => $today],
        'bad event_date'                   => ['employee_id' => $emp_id, 'event_type' => 'award', 'title' => 'x', 'event_date' => 'not-a-date'],
        'end_date before event_date'       => $base + ['event_type' => 'award', 'end_date' => date('Y-m-d', strtotime('-5 days'))],
        'promotion without designation'    => $base + ['event_type' => 'promotion'],
        'promotion negative salary'        => $base + ['event_type' => 'promotion', 'new_designation_id' => $desig_id, 'new_salary' => -5],
        'promotion nonexistent designation'=> $base + ['event_type' => 'promotion', 'new_designation_id' => 999999],
        'transfer without destination'     => $base + ['event_type' => 'transfer'],
        'transfer nonexistent department'  => $base + ['event_type' => 'transfer', 'new_department_id' => 999999],
        'warning bad severity'             => $base + ['event_type' => 'warning', 'severity' => 'nuclear'],
        'complaint without complainant'    => $base + ['event_type' => 'complaint'],
        'resignation without end_date'     => $base + ['event_type' => 'resignation'],
        'termination without type'         => $base + ['event_type' => 'termination'],
        'nonexistent employee'             => ['employee_id' => 99999999, 'event_type' => 'award', 'event_date' => $today, 'title' => 'x'],
    ];
    foreach ($bad as $label => $p) {
        $r = call('add_lifecycle_event', $p);
        ok(empty($r['success']), "rejects: $label");
    }

    // ── 4. Attachment validation (bad extension rejected before storage) ────
    $tmp = tempnam(sys_get_temp_dir(), 'lcx');
    file_put_contents($tmp, 'MZ fake exe');
    $cfgFile = tempnam(sys_get_temp_dir(), 'hrl');
    file_put_contents($cfgFile, json_encode([
        'session' => $ADMIN_SESSION, 'method' => 'POST',
        'post' => ['employee_id' => $emp_id, 'event_type' => 'award', 'event_date' => $today, 'title' => 'x', 'award_type' => 'Test'],
        'files' => ['attachment' => ['name' => 'evil.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => 0, 'size' => 11]],
    ]));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker add_lifecycle_event " . escapeshellarg($cfgFile));
    @unlink($cfgFile); @unlink($tmp);
    $s = strpos((string)$o, '{'); $r = $s === false ? [] : json_decode(substr($o, $s), true);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'not allowed') !== false, "rejects .exe attachment (extension whitelist)");

    // ── 5. List + filters ────────────────────────────────────────────────────
    $r = call('get_lifecycle_events', ['employee_id' => $emp_id], null, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 9, "list by employee returns all 9 events (8 types + forged-test)");
    ok(!empty($r['data']) && ($r['data'][0]['first_name'] ?? '') === '__LC'
        && array_key_exists('created_by_name', $r['data'][0] ?? []), "list rows carry joined employee + creator names");

    $r = call('get_lifecycle_events', ['employee_id' => $emp_id, 'event_type' => 'award'], null, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 1 && $r['data'][0]['event_type'] === 'award', "event_type filter works");

    $r = call('get_lifecycle_events', ['employee_id' => $emp_id, 'status' => 'approved'], null, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 0, "status filter works (no approved yet)");

    $r = call('get_lifecycle_events', ['employee_id' => $emp_id, 'date_from' => date('Y-m-d', strtotime('+1 day'))], null, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 0, "date_from filter works");

    // ── 6. Single fetch ──────────────────────────────────────────────────────
    $r = call('get_lifecycle_event', ['event_id' => $created['promotion']], null, 'GET');
    ok(!empty($r['success']) && $r['data']['event_type'] === 'promotion'
        && $r['data']['new_designation_name'] !== null, "single fetch returns event with resolved names");

    $r = call('get_lifecycle_event', ['event_id' => 99999999], null, 'GET');
    ok(empty($r['success']), "single fetch of unknown id fails cleanly");

    // ── 7. Project-scope denial (non-admin, employee outside scope) ─────────
    $noScope = [
        'user_id' => 999999, 'username' => 'scopetest', 'is_admin' => false, 'role_id' => 4,
        'permissions' => ['employee_lifecycle' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'review' => true, 'approve' => true]],
        'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()],
    ];
    $r = call('add_lifecycle_event', [
        'employee_id' => $emp_id, 'event_type' => 'award', 'event_date' => $today, 'title' => 'x', 'award_type' => 'T',
    ], $noScope);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'scope') !== false, "create denied for out-of-scope employee (non-admin)");

    $r = call('get_lifecycle_events', ['employee_id' => $emp_id], $noScope, 'GET');
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'scope') !== false, "list by out-of-scope employee denied (non-admin)");

    $r = call('get_lifecycle_events', [], $noScope, 'GET');
    $leaked = array_filter($r['data'] ?? [], fn($x) => (int)$x['employee_id'] === $emp_id);
    ok(!empty($r['success']) && count($leaked) === 0, "unfiltered list hides out-of-scope employee's events (non-admin)");

    $r = call('get_lifecycle_event', ['event_id' => $created['promotion']], $noScope, 'GET');
    ok(empty($r['success']), "single fetch of out-of-scope event denied (non-admin)");

    // ── 8. Permission gate ───────────────────────────────────────────────────
    $viewOnly = $noScope;
    $viewOnly['permissions']['employee_lifecycle'] = ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'review' => false, 'approve' => false];
    $viewOnly['scope']['projects'] = [$proj_id];
    $r = call('add_lifecycle_event', [
        'employee_id' => $emp_id, 'event_type' => 'award', 'event_date' => $today, 'title' => 'x', 'award_type' => 'T',
    ], $viewOnly);
    ok(empty($r['success']) && stripos($r['message'] ?? '', 'permission') !== false, "create denied without canCreate (view-only role)");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    // ── Cleanup fixtures ─────────────────────────────────────────────────────
    if ($emp_id) {
        $pdo->exec("DELETE FROM employee_lifecycle_events WHERE employee_id = $emp_id");
        $pdo->exec("DELETE FROM employees WHERE employee_id = $emp_id");
    }
    if ($made_project) {
        $pdo->exec("DELETE FROM projects WHERE project_id = $made_project");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
