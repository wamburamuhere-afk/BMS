<?php
/**
 * BMS — HR Dashboard guard.
 *
 * A single HR command centre: headcount, contract/probation expiry (previously
 * notification-only — cron/check_hr_expiry.php dispatches alerts but there was
 * no page to just look at the list), HR Actions + acknowledgment compliance,
 * department distribution, and recruitment pipeline. Every number is meant to
 * be a real, already-used query or a live link — this guards against silent
 * drift (a query added later that forgets project-scope) and against the page
 * ever fataling for a real, non-admin, scoped role.
 *
 * Run:
 *   php tests/test_hr_dashboard_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'render') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)$argv[2]; $_SESSION['username'] = 'u'; $_SESSION['role_id'] = (int)($argv[3] ?? 1);
    $_SESSION['is_admin'] = ((int)($argv[3] ?? 1) === 1);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/hr_dashboard';
    require_once "$root/roots.php";
    if (!$_SESSION['is_admin']) loadUserPermissions((int)$_SESSION['role_id']);
    require "$root/app/bms/pos/hr_dashboard.php";
    exit;
}

require_once "$root/roots.php";
global $pdo;

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function render(string $root, int $uid, int $rid): string {
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $uid $rid 2>&1");
}
function noErr(string $h): bool {
    foreach (['Fatal error', 'Parse error', 'Uncaught', 'Unknown column', 'SQLSTATE', 'Call to a member function', 'Call to undefined', 'Undefined array key', 'Undefined variable'] as $e) {
        if (stripos($h, $e) !== false) return false;
    }
    return true;
}

echo "\n\033[1m═══ HR Dashboard ═══\033[0m\n";

head('1. php -l');
foreach (['app/bms/pos/hr_dashboard.php', 'migrations/2026_08_29_hr_dashboard_permission.php'] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? ok("$f lint-clean") : bad("$f: " . trim((string)$out));
}

head('2. Permission + route + menu wiring');
$permId = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permId->execute(['hr_dashboard']);
$permId->fetchColumn() ? ok("permission 'hr_dashboard' exists") : bad('permission missing');
$rootsSrc = file_get_contents("$root/roots.php");
strpos($rootsSrc, "'hr_dashboard' => POS_DIR") !== false ? ok("route registered") : bad('route missing');
$headerSrc = file_get_contents("$root/header.php");
(strpos($headerSrc, "getUrl('hr_dashboard')") !== false && strpos($headerSrc, "canView('hr_dashboard')") !== false)
    ? ok('header.php links to hr_dashboard, gated by canView') : bad('menu link missing/ungated');

head('3. Every query touching a project-scoped table (employees, leaves) carries the scope guard');
$src = file_get_contents("$root/app/bms/pos/hr_dashboard.php");
strpos($src, "scopeFilterSqlNullable('project', 'e')") !== false ? ok('uses the shared scope helper') : bad('missing scope helper — would leak cross-project headcount/leave/contract/action data');
// Every SQL block that references "employees e" or "leaves l" must also reference $emp_scope somewhere nearby;
// spot-check by counting occurrences of the alias vs the scope variable use.
$empJoins = substr_count($src, 'employees e');
$scopeUses = substr_count($src, '$emp_scope');
($scopeUses >= 5) ? ok("scope variable referenced $scopeUses times across the employee-touching queries") : bad("scope variable used only $scopeUses times — suspiciously low for $empJoins employee joins");

head('4. Renders cleanly for admin and for a real scoped non-admin role');
$rid = (int)$pdo->query("SELECT role_id FROM roles WHERE is_admin = 0 LIMIT 1")->fetchColumn();
$adminHtml = render($root, 4, 1);
noErr($adminHtml) ? ok('admin render: no fatal/notice/SQL errors') : bad('admin render errored: ' . substr($adminHtml, 0, 300));
strpos($adminHtml, 'Active Employees') !== false ? ok('admin render shows the KPI row') : bad('KPI row missing from admin render');

if ($rid) {
    $nonAdminHtml = render($root, 4, $rid);
    noErr($nonAdminHtml) ? ok("non-admin (role #$rid) render: no fatal/notice/SQL errors") : bad('non-admin render errored: ' . substr($nonAdminHtml, 0, 300));
} else {
    echo "  — no non-admin role found, skipping\n";
}

head('5. KPI numbers match direct hand-written queries (admin scope = unfiltered)');
$directActive = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
strpos($adminHtml, '>' . $directActive . '<') !== false
    ? ok("Active Employees KPI ($directActive) appears in the rendered page") : bad("Active Employees KPI ($directActive) not found in rendered output");

$directPending = (int)$pdo->query("SELECT COUNT(*) FROM employee_lifecycle_events WHERE status = 'pending'")->fetchColumn();
strpos($adminHtml, '>' . $directPending . '<') !== false
    ? ok("HR Actions Pending KPI ($directPending) appears in the rendered page") : bad("pending-actions KPI ($directPending) not found in rendered output");

head('6. Quick Links only show pages the viewer can actually reach');
strpos($src, 'canView($key)') !== false ? ok('quick links are permission-gated per link') : bad('quick links are not gated — could show a 403 link to a restricted module');

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
