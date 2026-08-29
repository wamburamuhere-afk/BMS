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

$directInactive = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status != 'active'")->fetchColumn();
strpos($adminHtml, '>' . $directInactive . '<') !== false
    ? ok("Inactive Employees KPI ($directInactive) appears in the rendered page") : bad("Inactive Employees KPI ($directInactive) not found in rendered output");

$year = (int)date('Y');
$directNewHires = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE YEAR(hire_date) = $year")->fetchColumn();
strpos($adminHtml, '>' . $directNewHires . '<') !== false
    ? ok("New Hires KPI ($directNewHires) appears in the rendered page") : bad("New Hires KPI ($directNewHires) not found in rendered output");

$directPending = (int)$pdo->query("SELECT COUNT(*) FROM employee_lifecycle_events WHERE status = 'pending'")->fetchColumn();
strpos($adminHtml, '>' . $directPending . '<') !== false
    ? ok("HR Actions Pending KPI ($directPending) appears in the rendered page") : bad("pending-actions KPI ($directPending) not found in rendered output");

$directPayroll = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM payroll WHERE status = 'paid' AND year = $year")->fetchColumn();
strpos($adminHtml, 'TSh ' . number_format((float)$directPayroll, 0)) !== false
    ? ok("Payroll Paid KPI (TSh " . number_format((float)$directPayroll, 0) . ") appears in the rendered page") : bad('Payroll Paid KPI not found in rendered output');

head('6. Quick Links only show pages the viewer can actually reach');
strpos($src, 'canView($key)') !== false ? ok('quick links are permission-gated per link') : bad('quick links are not gated — could show a 403 link to a restricted module');

head('7. Stat cards use the standard app-wide card background (#d1e7dd), no pie/bar chart used');
$cardBgCount = substr_count($src, 'style="background:#d1e7dd;"');
($cardBgCount >= 7) ? ok("$cardBgCount stat cards use the standard #d1e7dd background") : bad("only $cardBgCount cards use #d1e7dd — expected at least 7");
strpos($src, "type: 'bar'") === false ? ok('no bar chart used (as requested)') : bad('a bar chart is still present');
strpos($src, "type: 'doughnut'") === false ? ok('no doughnut/pie chart used (as requested)') : bad('a doughnut/pie chart is still present');
strpos($src, "type: 'line'") !== false ? ok('the headline chart is a line chart (matches app/dashboard.php style)') : bad('no line chart found');
(strpos($src, "yAxisID: 'yMoney'") !== false && strpos($src, "yAxisID: 'yCount'") !== false)
    ? ok('dual y-axis: money (payroll) and count (headcount) plotted on separate scales') : bad('dual y-axis setup missing — payroll and headcount would be unreadable on one scale');

head('8. Employees-vs-Payroll monthly series: headcount reconstruction is internally consistent');
strpos($src, "'termination', 'resignation'") !== false ? ok('headcount reconstruction subtracts approved terminations/resignations') : bad('exit subtraction missing — headcount trend would only ever go up');
// The LAST point of the series (as-of today) must equal the live Active Employees KPI
// when nobody has ever been hired after leaving and rehired (the one known approximation
// this reconstruction makes) — cross-check it is at least in the right ballpark.
preg_match('/data: \[([\d.,]*)\]\s*,\s*yAxisID: .yCount./s', $adminHtml, $m2)
    ?: preg_match("/'Headcount'.*?data: \\[([\\d.,]*)\\]/s", $adminHtml, $m2);
if (!empty($m2[1])) {
    $series = array_map('intval', explode(',', $m2[1]));
    $lastPoint = end($series);
    (abs($lastPoint - $directActive) <= 5)
        ? ok("chart's latest headcount point ($lastPoint) is close to live Active Employees ($directActive)")
        : bad("chart's latest headcount point ($lastPoint) is far from live Active Employees ($directActive) — reconstruction may be wrong");
} else {
    echo "  — could not extract the headcount series from rendered output to cross-check\n";
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
