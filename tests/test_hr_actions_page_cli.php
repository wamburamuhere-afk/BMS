<?php
/**
 * HR Actions page (Tier 1, Phase 1.4) CLI test.
 *   php tests/test_hr_actions_page_cli.php
 *
 * Renders app/bms/pos/hr_actions.php against the live DB as admin and as a
 * view-only role, asserting: no PHP/SQL errors, permission-gated buttons,
 * UI-standard compliance (gear dropdown, Select2, SweetAlert, mobile cards,
 * stat cards, DataTable), the D5 catch-up call, and a full page-path
 * end-to-end (create via the page's API → approve → employee row changed).
 */
$root = dirname(__DIR__);

// ── workers ──────────────────────────────────────────────────────────────────
if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $who = $argv[2] ?? 'admin';
    // Real users so header.php's session re-derivation matches: argv[3]/[4]
    // carry the ids resolved by the parent from the live users table.
    if ($who === 'admin') {
        $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    } else { // viewer — a real non-admin whose role holds view-only on employee_lifecycle
        $_SESSION['user_id'] = (int)($argv[3] ?? 0); $_SESSION['username'] = 'viewer'; $_SESSION['is_admin'] = false; $_SESSION['role_id'] = (int)($argv[4] ?? 0);
        $_SESSION['scope'] = ['is_admin' => false, 'projects' => ['*'], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()];
        if (function_exists('loadUserPermissions')) { /* loaded after roots below */ }
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/hr_actions';
    if ($who !== 'admin') {
        require_once "$root/roots.php";
        loadUserPermissions((int)$_SESSION['role_id']);   // real DB permissions for the role
    }
    require "$root/app/bms/pos/hr_actions.php";
    exit;
}
if (($argv[1] ?? '') === 'worker') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[3]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    require_once "$root/roots.php";
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function render($who, $uid = 0, $rid = 0) { return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $who $uid $rid 2>&1"); }
function noErr($html) { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function', 'Call to undefined'] as $e) if (stripos($html, $e) !== false) return false; return true; }
function call($ep, $payload, $session, $method = 'POST') {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'hap'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => $o] : json_decode(substr($o, $s), true);
}

$pageSrc  = file_get_contents("$root/app/bms/pos/hr_actions.php");
$modalSrc = file_get_contents("$root/app/bms/pos/includes/lifecycle_modal.php");

$emp_id = 0;
try {
    // ── 1. Lint ──────────────────────────────────────────────────────────────
    foreach (['app/bms/pos/hr_actions.php', 'app/bms/pos/includes/lifecycle_modal.php'] as $f) {
        $rc = 0; $o = []; exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
        ok($rc === 0, "lint $f");
    }

    // ── 2. UI standards in source ────────────────────────────────────────────
    ok(strpos($pageSrc, "autoEnforcePermission('employee_lifecycle')") !== false, "page enforces the employee_lifecycle permission");
    ok(strpos($pageSrc, 'applyDueLifecycleEffects($pdo)') !== false, "page runs the D5 resignation catch-up on load");
    ok(strpos($pageSrc, 'bi-gear-fill') !== false && strpos($pageSrc, 'dropdown-menu-end') !== false, "§UI-5 gear dropdown actions");
    ok(strpos($pageSrc, 'Swal.fire') !== false && strpos($pageSrc, 'alert(') === false, "§UI-4 SweetAlert only, no native alert");
    ok(strpos($pageSrc, 'renderCards') !== false && strpos($pageSrc, 'cardView') !== false, "§UI-7 mobile card view wired");
    ok(strpos($pageSrc, 'clear().rows.add(') !== false, "§UI-2 AJAX redraw (no location.reload for list refresh)");
    ok(strpos($pageSrc, '#e7f0ff') !== false && strpos($pageSrc, '#b6ccfe') !== false, "§UI-1 stat card colours");
    ok(strpos($pageSrc, "scopeFilterSqlNullable('project', 'e')") !== false, "stat cards scope-filtered like the API");
    ok(strpos($pageSrc, 'buildUrl(') !== false && strpos($pageSrc, 'getUrl(') !== false, "§10 URL helpers (no hardcoded paths)");
    ok(preg_match('/\bfa fa-/', $pageSrc) === 0, "§UI-8 Bootstrap icons only");
    ok(strpos($modalSrc, 'csrf_token()') !== false, "§21 CSRF token in the shared modal form");
    ok(strpos($modalSrc, "scopeFilterSql('project'") !== false, "modal project dropdown uses strict scope (§23 rule 1)");
    ok(strpos($modalSrc, 'select2') !== false && strpos($modalSrc, 'minimumInputLength') !== false, "§UI-3 employee picker is AJAX Select2");
    ok(strpos($modalSrc, '$lifecycle_preselect') !== false, "modal supports pre-selected employee (shared with employee_details)");

    // ── 3. Render as admin (real admin user from the live users table) ───────
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    // Viewer: real user whose role holds view-only (no create/approve) on employee_lifecycle
    $viewer = $pdo->query("
        SELECT u.user_id, u.role_id FROM users u
        JOIN roles r ON r.role_id = u.role_id AND r.is_admin = 0
        JOIN role_permissions rp ON rp.role_id = u.role_id
        JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employee_lifecycle'
        WHERE rp.can_view = 1 AND rp.can_create = 0 AND rp.can_approve = 0
        LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($admin_uid && $viewer, "found real admin + view-only users for render tests");

    $html = render('admin', $admin_uid);
    ok(noErr($html), "admin render: no PHP/SQL errors");
    ok(strpos($html, 'HR Actions') !== false, "admin render: page title present");
    ok(strpos($html, 'New Action') !== false, "admin render: New Action button visible (canCreate)");
    ok(strpos($html, 'lifecycleModal') !== false, "admin render: shared modal included");
    ok(strpos($html, 'hrActionsTable') !== false, "admin render: DataTable present");
    ok(strpos($html, 'Pending Approval') !== false, "admin render: stat cards render");
    ok(substr_count($html, 'CAN_APPROVE = true') === 1, "admin render: approve flag passed to JS");

    // ── 4. Render as view-only role ──────────────────────────────────────────
    $html = render('viewer', (int)$viewer['user_id'], (int)$viewer['role_id']);
    ok(noErr($html), "viewer render: no PHP/SQL errors");
    ok(strpos($html, 'New Action') === false, "viewer render: New Action button hidden");
    ok(strpos($html, 'lifecycleModal') === false, "viewer render: create modal not even included");
    ok(preg_match('/CAN_APPROVE\s*=\s*false/', $html) && preg_match('/CAN_DELETE\s*=\s*false/', $html),
        "viewer render: approve/delete flags false in JS");

    // ── 5. End-to-end through the page's own endpoints ───────────────────────
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $desigs = $pdo->query("SELECT designation_id FROM designations WHERE status='active' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $dept_id = (int)$pdo->query("SELECT department_id FROM departments WHERE status='active' LIMIT 1")->fetchColumn();
    $d1 = (int)($desigs[0] ?? 0); $d2 = (int)($desigs[1] ?? $desigs[0] ?? 0);
    $pdo->prepare("INSERT INTO employees (first_name, last_name, employment_status, designation_id, department_id, basic_salary, created_at)
                   VALUES ('__HAP', 'Fixture', 'active', ?, ?, 400000, NOW())")->execute([$d1, $dept_id]);
    $emp_id = (int)$pdo->lastInsertId();

    $r = call('add_lifecycle_event', [
        'employee_id' => $emp_id, 'event_type' => 'promotion', 'event_date' => date('Y-m-d'),
        'title' => 'Page e2e promotion', 'new_designation_id' => $d2, 'new_salary' => 650000,
    ], $ADMIN);
    $ev = (int)($r['event_id'] ?? 0);
    ok($ev > 0, "e2e: create through the page's add endpoint");
    $r = call('change_lifecycle_status', ['event_id' => $ev, 'action' => 'approve'], $ADMIN);
    $e = $pdo->query("SELECT designation_id, basic_salary FROM employees WHERE employee_id = $emp_id")->fetch(PDO::FETCH_ASSOC);
    ok(!empty($r['success']) && (int)$e['designation_id'] === $d2 && (float)$e['basic_salary'] === 650000.0,
        "e2e: approve through the page's workflow endpoint applies the change");
    $r = call('get_lifecycle_events', ['employee_id' => $emp_id], $ADMIN, 'GET');
    ok(!empty($r['success']) && count($r['data']) === 1 && $r['data'][0]['status'] === 'approved',
        "e2e: page list endpoint shows the approved event");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($emp_id) {
        $pdo->exec("DELETE FROM employee_lifecycle_events WHERE employee_id = $emp_id");
        $pdo->exec("DELETE FROM employees WHERE employee_id = $emp_id");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
