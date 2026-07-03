<?php
/**
 * Org structure & org chart (Tier 2, Phase 2.4) CLI test.
 *   php tests/test_org_structure_cli.php
 *
 * Proves: D15 cycle guard (self, direct, deep), D14 dual-write of the
 * manager's name into the legacy reporting_to varchar (and clearing both on
 * unset), optional-field back-compat on add_employee.php/update_employee.php
 * (old clients that never send reporting_to_id leave it untouched), scope +
 * permission denials, and the org_chart.php / employee_details.php pages
 * render cleanly with 0/1/N-level trees and a legacy-varchar-only employee.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)($argv[3] ?? 4); $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $page = $argv[2];
    if ($page === 'org_chart') {
        $_SERVER['REQUEST_URI'] = '/org_chart';
        require "$root/app/bms/pos/org_chart.php";
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
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/api/{$argv[2]}.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function call($ep, $payload, $session, $method = 'POST') {
    global $root;
    $cfg = ['session' => $session, 'method' => $method, ($method === 'GET' ? 'get' : 'post') => $payload];
    $f = tempnam(sys_get_temp_dir(), 'org'); file_put_contents($f, json_encode($cfg));
    $o = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " worker $ep " . escapeshellarg($f));
    @unlink($f);
    $s = strpos((string)$o, '{');
    return $s === false ? ['_raw' => (string)$o] : json_decode(substr($o, $s), true);
}
function render($page, $uid, $empId = 0) {
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $page $uid $empId 2>&1");
}
function noErr($html) { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function', 'Call to undefined'] as $e) if (stripos($html, $e) !== false) return false; return true; }

$ids = [];
try {
    $admin_uid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    $ADMIN = ['user_id' => $admin_uid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1];
    $NOPERM = ['user_id' => 999905, 'username' => 'noperm', 'is_admin' => false, 'role_id' => 999,
        'permissions' => [], 'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];

    $mk = function ($tag) use ($pdo) {
        $pdo->exec("INSERT INTO employees (first_name, last_name, employee_number, employment_status, created_at)
                     VALUES ('__ORG', '$tag', '__ORG-$tag', 'active', NOW())");
        return (int)$pdo->lastInsertId();
    };
    $ids['a'] = $mk('A'); $ids['b'] = $mk('B'); $ids['c'] = $mk('C'); $ids['d'] = $mk('D');

    // ── 1. Basic assignment + dual-write ─────────────────────────────────────
    $r = call('update_reporting_line', ['employee_id' => $ids['b'], 'manager_id' => $ids['a']], $ADMIN);
    ok(!empty($r['success']), "B assigned to report to A: " . ($r['message'] ?? ''));
    $row = $pdo->query("SELECT reporting_to_id, reporting_to FROM employees WHERE employee_id = {$ids['b']}")->fetch(PDO::FETCH_ASSOC);
    ok((int)$row['reporting_to_id'] === $ids['a'], "D14: reporting_to_id set correctly");
    ok($row['reporting_to'] === '__ORG A', "D14: legacy reporting_to varchar dual-written with manager's name");

    // ── 2. D15 cycle guard ────────────────────────────────────────────────────
    $r = call('update_reporting_line', ['employee_id' => $ids['a'], 'manager_id' => $ids['a']], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'], 'themselves') !== false, "rejects self-reference");

    $r = call('update_reporting_line', ['employee_id' => $ids['a'], 'manager_id' => $ids['b']], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'], 'cycle') !== false, "rejects direct cycle (A already manages B)");

    // Build a chain: C -> B -> A (C reports to B, B already reports to A)
    call('update_reporting_line', ['employee_id' => $ids['c'], 'manager_id' => $ids['b']], $ADMIN);
    $r = call('update_reporting_line', ['employee_id' => $ids['a'], 'manager_id' => $ids['c']], $ADMIN);
    ok(empty($r['success']) && stripos($r['message'], 'cycle') !== false, "rejects deep cycle (A is an ancestor of C via B)");

    // Non-cyclic reassignment still works: D reports to C
    $r = call('update_reporting_line', ['employee_id' => $ids['d'], 'manager_id' => $ids['c']], $ADMIN);
    ok(!empty($r['success']), "non-cyclic assignment (D -> C) still succeeds");

    // ── 3. Clearing a manager ────────────────────────────────────────────────
    $r = call('update_reporting_line', ['employee_id' => $ids['b'], 'manager_id' => ''], $ADMIN);
    ok(!empty($r['success']), "manager cleared: " . ($r['message'] ?? ''));
    $row = $pdo->query("SELECT reporting_to_id, reporting_to FROM employees WHERE employee_id = {$ids['b']}")->fetch(PDO::FETCH_ASSOC);
    ok($row['reporting_to_id'] === null && $row['reporting_to'] === null, "D14: both columns cleared together");

    // Re-link B -> A for the org-chart render below
    call('update_reporting_line', ['employee_id' => $ids['b'], 'manager_id' => $ids['a']], $ADMIN);

    // ── 4. Permission + scope denial ─────────────────────────────────────────
    $r = call('update_reporting_line', ['employee_id' => $ids['b'], 'manager_id' => $ids['a']], $NOPERM);
    ok(empty($r['success']), "update_reporting_line denied without canEdit('employees')");

    // ── 5. Optional-field back-compat on add_employee.php (source-level) ─────
    // add_employee.php requires mandatory document uploads unrelated to this
    // feature, which real move_uploaded_file() can't satisfy from a CLI
    // subprocess (same limitation noted in test_employee_documents_cli.php) —
    // so this is verified at the source level; the live dual-write logic is
    // identical to update_employee.php's, which IS driven live below.
    $addSrc = file_get_contents("$root/api/add_employee.php");
    ok(strpos($addSrc, "\$_POST['reporting_to_id']") !== false, "add_employee.php: reads the optional reporting_to_id field");
    ok(strpos($addSrc, 'reporting_to_id') !== false && preg_match('/INSERT INTO employees[\s\S]*reporting_to_id/', $addSrc) === 1, "add_employee.php: reporting_to_id included in the INSERT column list");
    ok(strpos($addSrc, 'Selected manager does not exist') !== false, "add_employee.php: validates the chosen manager exists");

    $ids['e'] = $mk('NoMgrField');
    // update_employee.php requires the 3 mandatory documents to already exist
    // (unrelated to reporting_to_id) — satisfy that unrelated rule so this
    // fixture can go through the real endpoint.
    $pdo->prepare("UPDATE employees SET documents = ? WHERE employee_id = ?")
        ->execute([json_encode(['cv' => 'x', 'id' => 'x', 'certificates' => 'x']), $ids['e']]);

    // update_employee.php: editing WITHOUT sending reporting_to_id must not touch it
    if (!empty($ids['e'])) {
        call('update_reporting_line', ['employee_id' => $ids['e'], 'manager_id' => $ids['a']], $ADMIN);
        $r = call('update_employee', ['employee_id' => $ids['e'], 'notes' => 'touched by back-compat test'], $ADMIN);
        ok(!empty($r['success']), "update_employee without reporting_to_id succeeds: " . ($r['message'] ?? ''));
        $row = $pdo->query("SELECT reporting_to_id FROM employees WHERE employee_id = {$ids['e']}")->fetch(PDO::FETCH_ASSOC);
        ok((int)$row['reporting_to_id'] === $ids['a'], "update_employee: reporting_to_id left untouched when field is absent from the request");
    }

    // update_employee.php: sending reporting_to_id DOES dual-write
    if (!empty($ids['e'])) {
        $r = call('update_employee', ['employee_id' => $ids['e'], 'reporting_to_id' => $ids['d']], $ADMIN);
        ok(!empty($r['success']), "update_employee with reporting_to_id succeeds: " . ($r['message'] ?? ''));
        $row = $pdo->query("SELECT reporting_to_id, reporting_to FROM employees WHERE employee_id = {$ids['e']}")->fetch(PDO::FETCH_ASSOC);
        ok((int)$row['reporting_to_id'] === $ids['d'] && $row['reporting_to'] === '__ORG D', "update_employee: explicit reporting_to_id dual-writes the manager name");
    }

    // ── 6. Source: legacy-varchar-only employee still renders (no linked manager) ─
    $pdo->exec("UPDATE employees SET reporting_to = 'Some Legacy Manager Name', reporting_to_id = NULL WHERE employee_id = {$ids['d']}");
    $orgSrc = file_get_contents("$root/app/bms/pos/employees.php");
    ok(strpos($orgSrc, 'reporting_to_id') !== false, "employees.php: modal field renamed to reporting_to_id");
    ok(strpos($orgSrc, 'id="reporting_to"') === false, "employees.php: old free-text reporting_to input removed");
    ok(strpos($orgSrc, 'populateReportingTo') !== false, "employees.php: legacy-value hint helper present");

    // ── 7. Runtime renders ────────────────────────────────────────────────────
    $html = render('org_chart', $admin_uid);
    ok(noErr($html), "org_chart.php: no PHP/SQL errors");
    ok(strpos($html, 'Organisation Chart') !== false, "org_chart.php: page renders");

    $html = render('details', $admin_uid, $ids['a']);
    ok(noErr($html), "employee_details.php (root, has reports): no PHP/SQL errors");
    ok(strpos($html, 'Direct Reports') !== false && strpos($html, '__ORG B') !== false, "employee_details.php: Direct Reports card lists B under A");

    $html = render('details', $admin_uid, $ids['e']);
    ok(noErr($html), "employee_details.php (leaf, no direct reports): no PHP/SQL errors");
    ok(strpos($html, 'No direct reports') !== false, "employee_details.php: clean empty state when nobody reports here");

    $html = render('details', $admin_uid, $ids['d']);
    ok(noErr($html), "employee_details.php (legacy-varchar-only manager link): no PHP/SQL errors");

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    foreach ($ids as $id) {
        if ($id) $pdo->exec("DELETE FROM employees WHERE employee_id = $id");
    }
    echo "  (fixtures cleaned)\n";
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
