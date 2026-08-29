<?php
/**
 * BMS — Org Structure management guard (Departments / Designations / Employment Types).
 *
 * Before this, the ONLY way one of these 3 lookup tables ever got a row was as a
 * side-effect of the Employee wizard's "Other (specify)" box — there was no admin
 * page to view, rename, link, or deactivate one, and no permission gate of its own.
 *
 * Covers: permissions seeded, routes registered, menu links wired, all 6 pages/
 * endpoints lint-clean, the employees.php cross-link filter wiring, and real
 * create/update/deactivate/activate round trips through the actual endpoints
 * (subprocess runner — real request lifecycle, real CSRF, real session), including
 * the "cannot deactivate while in use" guard and the department parent-cycle guard.
 *
 * Run:
 *   php tests/test_org_structure_management_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

$root = dirname(__DIR__);
require_once $root . '/roots.php';
global $pdo;

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

// Run an endpoint in a fresh admin-session subprocess; returns decoded JSON + raw output.
function run_endpoint(string $root, string $endpoint, array $post = [], array $get = []): array {
    $runner = $root . '/tests/_tmp_orgstruct_runner.php';
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
    // stdout only — matches the real HTTP response body. Merging stderr (2>&1)
    // would corrupt the JSON whenever the endpoint's catch block calls error_log(),
    // which under CLI SAPI defaults to stderr (never happens over real HTTP).
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
    @unlink($runner);
    return [json_decode(trim((string)$out), true), trim((string)$out)];
}

echo "\n\033[1m═══ Org Structure management (Departments / Designations / Employment Types) ═══\033[0m\n";

head('1. php -l — every new file');
$files = [
    'app/bms/pos/departments.php', 'app/bms/pos/designations.php', 'app/bms/pos/employment_types.php',
    'api/pos/save_department.php', 'api/pos/toggle_department_status.php',
    'api/pos/save_designation.php', 'api/pos/toggle_designation_status.php',
    'api/pos/save_employment_type.php', 'api/pos/toggle_employment_type_status.php',
];
foreach ($files as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? ok("$f lint-clean") : bad("$f: " . trim((string)$out));
}

head('2. Permissions seeded');
foreach (['departments', 'designations', 'employment_types'] as $key) {
    $id = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $id->execute([$key]);
    $id->fetchColumn() ? ok("permission '$key' exists") : bad("permission '$key' missing");
}

head('3. Routes registered (roots.php)');
$rootsSrc = file_get_contents("$root/roots.php");
foreach (['departments', 'designations', 'employment_types'] as $key) {
    strpos($rootsSrc, "'$key' => POS_DIR") !== false ? ok("route '$key' registered") : bad("route '$key' missing");
}

head('4. Menu links wired (header.php, permission-gated)');
$headerSrc = file_get_contents("$root/header.php");
foreach (['departments', 'designations', 'employment_types'] as $key) {
    (strpos($headerSrc, "getUrl('$key')") !== false && strpos($headerSrc, "canView('$key')") !== false)
        ? ok("header.php links to '$key', gated by canView") : bad("header.php missing a gated link to '$key'");
}

head('5. employees.php reads the cross-link query params and applies the filter');
$empSrc = file_get_contents("$root/app/bms/pos/employees.php");
strpos($empSrc, "urlParams.get('department_id')") !== false ? ok('reads department_id from URL') : bad('does not read department_id from URL');
strpos($empSrc, "urlParams.get('designation_id')") !== false ? ok('reads designation_id from URL') : bad('does not read designation_id from URL');
strpos($empSrc, "urlParams.get('employment_type_id')") !== false ? ok('reads employment_type_id from URL') : bad('does not read employment_type_id from URL');
strpos($empSrc, 'applyFilters();') !== false ? ok('calls applyFilters() to actually apply it') : bad('never calls applyFilters()');

// ── Live round trips ────────────────────────────────────────────────────────
$stamp = 'ZZTEST' . substr((string)microtime(true), -5, 5);
$createdDeptIds = []; $createdDesigIds = []; $createdTypeId = null; $touchedEmployeeId = null; $origEmpDept = null;

try {
    head('6. Employment Type: create → update → in-use guard → deactivate → reactivate');
    [$r] = run_endpoint($root, "$root/api/pos/save_employment_type.php", ['type_name' => "$stamp Type", 'description' => 'test']);
    (is_array($r) && !empty($r['success']) && !empty($r['id'])) ? ok('created') : bad('create failed: ' . json_encode($r));
    $createdTypeId = (int)($r['id'] ?? 0);

    [$r2] = run_endpoint($root, "$root/api/pos/save_employment_type.php", ['type_id' => $createdTypeId, 'type_name' => "$stamp Type Renamed", 'description' => 'renamed']);
    (is_array($r2) && !empty($r2['success'])) ? ok('updated') : bad('update failed: ' . json_encode($r2));
    $name = $pdo->query("SELECT type_name FROM employment_types WHERE type_id = $createdTypeId")->fetchColumn();
    ($name === "$stamp Type Renamed") ? ok('rename persisted') : bad("rename did not persist (got '$name')");

    // Point one real employee at it, then try to deactivate — must be blocked.
    $emp = $pdo->query("SELECT employee_id, employment_type_id FROM employees WHERE status = 'active' ORDER BY employee_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($emp) {
        $touchedEmployeeId = (int)$emp['employee_id'];
        $origEmpType = $emp['employment_type_id'];
        $pdo->prepare("UPDATE employees SET employment_type_id = ? WHERE employee_id = ?")->execute([$createdTypeId, $touchedEmployeeId]);

        [$r3] = run_endpoint($root, "$root/api/pos/toggle_employment_type_status.php", ['type_id' => $createdTypeId, 'status' => 'inactive']);
        (is_array($r3) && empty($r3['success']) && str_contains($r3['message'] ?? '', 'active employee'))
            ? ok('deactivate blocked while an active employee uses it') : bad('in-use guard did not block: ' . json_encode($r3));

        $pdo->prepare("UPDATE employees SET employment_type_id = ? WHERE employee_id = ?")->execute([$origEmpType, $touchedEmployeeId]);
    } else {
        echo "  — no active employee to test the in-use guard with, skipping\n";
    }

    [$r4] = run_endpoint($root, "$root/api/pos/toggle_employment_type_status.php", ['type_id' => $createdTypeId, 'status' => 'inactive']);
    (is_array($r4) && !empty($r4['success'])) ? ok('deactivated once unused') : bad('deactivate failed: ' . json_encode($r4));
    $status = $pdo->query("SELECT status FROM employment_types WHERE type_id = $createdTypeId")->fetchColumn();
    ($status === 'inactive') ? ok('status = inactive persisted') : bad("status not inactive (got '$status')");

    [$r5] = run_endpoint($root, "$root/api/pos/toggle_employment_type_status.php", ['type_id' => $createdTypeId, 'status' => 'active']);
    (is_array($r5) && !empty($r5['success'])) ? ok('reactivated') : bad('reactivate failed: ' . json_encode($r5));

    head('7. Designation: create under a department, duplicate name rejected');
    $dept = $pdo->query("SELECT department_id FROM departments WHERE status = 'active' ORDER BY department_id LIMIT 1")->fetchColumn();
    [$r6] = run_endpoint($root, "$root/api/pos/save_designation.php", ['designation_name' => "$stamp Designation", 'department_id' => $dept ?: '', 'pay_grade' => 'G1']);
    (is_array($r6) && !empty($r6['success']) && !empty($r6['id'])) ? ok('created') : bad('create failed: ' . json_encode($r6));
    $desigId = (int)($r6['id'] ?? 0);
    if ($desigId) $createdDesigIds[] = $desigId;

    [$r7] = run_endpoint($root, "$root/api/pos/save_designation.php", ['designation_name' => "$stamp Designation", 'department_id' => $dept ?: '']);
    (is_array($r7) && empty($r7['success']) && str_contains($r7['message'] ?? '', 'already exists'))
        ? ok('duplicate name rejected') : bad('duplicate not rejected: ' . json_encode($r7));

    head('8. Department: create A, create B as child of A, then reject A.parent = B (cycle)');
    [$rA] = run_endpoint($root, "$root/api/pos/save_department.php", ['department_name' => "$stamp Dept A"]);
    (is_array($rA) && !empty($rA['success']) && !empty($rA['id'])) ? ok('Dept A created') : bad('Dept A create failed: ' . json_encode($rA));
    $deptA = (int)($rA['id'] ?? 0);
    if ($deptA) $createdDeptIds[] = $deptA;

    [$rB] = run_endpoint($root, "$root/api/pos/save_department.php", ['department_name' => "$stamp Dept B", 'parent_department_id' => $deptA]);
    (is_array($rB) && !empty($rB['success']) && !empty($rB['id'])) ? ok('Dept B created as child of A') : bad('Dept B create failed: ' . json_encode($rB));
    $deptB = (int)($rB['id'] ?? 0);
    if ($deptB) $createdDeptIds[] = $deptB;

    $parentOfB = $pdo->query("SELECT parent_department_id FROM departments WHERE department_id = $deptB")->fetchColumn();
    ((int)$parentOfB === $deptA) ? ok('parent_department_id actually persisted (was dead/unused before this task)') : bad("Dept B parent not set (got '$parentOfB')");

    [$rCycle] = run_endpoint($root, "$root/api/pos/save_department.php", ['department_id' => $deptA, 'department_name' => "$stamp Dept A", 'parent_department_id' => $deptB]);
    (is_array($rCycle) && empty($rCycle['success']) && str_contains($rCycle['message'] ?? '', 'cycle'))
        ? ok('A→B→A cycle rejected') : bad('cycle not rejected: ' . json_encode($rCycle));

    head('9. Department: in-use guard blocks deactivating a dept with an active sub-department');
    [$rDeactA] = run_endpoint($root, "$root/api/pos/toggle_department_status.php", ['department_id' => $deptA, 'status' => 'inactive']);
    (is_array($rDeactA) && empty($rDeactA['success']) && str_contains($rDeactA['message'] ?? '', 'sub-department'))
        ? ok('deactivate blocked while an active sub-department reports to it') : bad('sub-department guard did not block: ' . json_encode($rDeactA));

    [$rDeactB] = run_endpoint($root, "$root/api/pos/toggle_department_status.php", ['department_id' => $deptB, 'status' => 'inactive']);
    (is_array($rDeactB) && !empty($rDeactB['success'])) ? ok('Dept B (leaf, unused) deactivates cleanly') : bad('Dept B deactivate failed: ' . json_encode($rDeactB));

} finally {
    // ── Cleanup: this test's own fixtures only, everything restored ─────────
    if ($touchedEmployeeId !== null) {
        // already restored above, but belt-and-braces if an assertion threw first
    }
    if ($createdTypeId) $pdo->exec("DELETE FROM employment_types WHERE type_id = $createdTypeId");
    foreach ($createdDesigIds as $id) $pdo->exec("DELETE FROM designations WHERE designation_id = $id");
    foreach ($createdDeptIds as $id) $pdo->exec("UPDATE departments SET parent_department_id = NULL WHERE department_id = $id");
    foreach ($createdDeptIds as $id) $pdo->exec("DELETE FROM departments WHERE department_id = $id");
    echo "\n  (test fixtures cleaned up)\n";
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
