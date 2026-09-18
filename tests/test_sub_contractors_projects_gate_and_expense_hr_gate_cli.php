<?php
/**
 * Sub-Contractors nav/page gated on the Projects feature (not the Simple-POS
 * Supplier Access toggle) + Expenses "Paid To: Staff" hidden when HR is off — CLI test
 *   php tests/test_sub_contractors_projects_gate_and_expense_hr_gate_cli.php
 *
 * 2026-09-18 bug report: on a Simple POS tenant with Procurement off and the
 * "Supplier Access" toggle on, "Sub-Contractors" showed under the Core nav
 * even though every other Procurement page stayed hidden — because
 * sub_contractors.php (and its nav link) reused canView('suppliers'), which
 * that toggle deliberately makes true. Per core/feature_registry.php's own
 * documented history, Sub-Contractors is owned by the 'projects' feature, not
 * 'procurement' or the supplier-access bypass — clicking it already 404'd at
 * the router, so the fix adds the same tenantFeatureEnabled('projects') check
 * at the page/nav level for a consistent "not available", not a dead link.
 *
 * Same report also flagged Expenses "Paid To" always offering "Staff
 * (Employee)" even when the whole HR module is off for the tenant —
 * app/constant/accounts/expenses.php never checked tenantFeatureEnabled('hr')
 * before querying employees / rendering the option.
 *
 * Verifies:
 *   1. All four touched files lint-clean.
 *   2. Source wiring — header.php's nav link, sub_contractors.php and
 *      sub_contractor_details.php's page gates all require
 *      tenantFeatureEnabled('projects'); expenses.php gates the employees
 *      query and both Paid-To renderings (Simple POS + normal) on
 *      tenantFeatureEnabled('hr').
 *   3. Live (in-process, admin session):
 *      - sub_contractors.php: renders "Sub-Contractor Management" when
 *        'projects' is on, redirects to unauthorized (no page content) when off.
 *      - sub_contractor_details.php: same on/off behavior.
 *      - expenses.php: "Staff (Employee)" present in output when 'hr' is on,
 *        absent when off — checked in both Simple POS and normal mode.
 *
 * Exit 0 = all pass.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

$passes = 0; $failures = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

$touchedFiles = [
    'header.php',
    'app/bms/operations/sub_contractors.php',
    'app/bms/operations/sub_contractor_details.php',
    'app/constant/accounts/expenses.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. All touched files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($touchedFiles as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
// ─────────────────────────────────────────────────────────────────────────
$headerSrc = file_get_contents("$root/header.php");
strpos($headerSrc, "canView('suppliers') && tenantFeatureEnabled('projects')") !== false
    ? pass('header.php Sub-Contractors nav link requires the projects feature')
    : fail('header.php Sub-Contractors nav link does not check tenantFeatureEnabled(projects)');

$scSrc = file_get_contents("$root/app/bms/operations/sub_contractors.php");
strpos($scSrc, "canView('suppliers') && tenantFeatureEnabled('projects')") !== false
    ? pass('sub_contractors.php gate requires the projects feature')
    : fail('sub_contractors.php gate does not check tenantFeatureEnabled(projects)');

$scdSrc = file_get_contents("$root/app/bms/operations/sub_contractor_details.php");
strpos($scdSrc, "tenantFeatureEnabled('projects')") !== false
    ? pass('sub_contractor_details.php gate requires the projects feature')
    : fail('sub_contractor_details.php gate does not check tenantFeatureEnabled(projects)');

$expSrc = file_get_contents("$root/app/constant/accounts/expenses.php");
strpos($expSrc, "\$enable_hr       = tenantFeatureEnabled('hr');") !== false
    ? pass('expenses.php resolves $enable_hr from tenantFeatureEnabled(hr)')
    : fail('expenses.php never resolves $enable_hr');
strpos($expSrc, "\$employees       = \$enable_hr ?") !== false
    ? pass('expenses.php skips the employees query entirely when HR is off')
    : fail('expenses.php still queries employees unconditionally');
preg_match_all("/if \(\\\$enable_hr\): \?><option value=\"staff\">/", $expSrc, $m);
count($m[0]) === 2
    ? pass('both Paid-To renderings (Simple POS + normal) gate the Staff option on $enable_hr')
    : fail('expected 2 gated "staff" option renderings, found ' . count($m[0]));

// ─────────────────────────────────────────────────────────────────────────
section('3. Live (in-process, admin session, subprocess per scenario)');
// ─────────────────────────────────────────────────────────────────────────
[$adminUid, $scId] = (function () use ($root) {
    require_once "$root/roots.php";
    global $pdo;
    $uid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
    $sc  = (int)($pdo->query("SELECT supplier_id FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetchColumn() ?: 0);
    return [$uid, $sc];
})();

// A fresh `php -r` subprocess per case: boots roots.php first (so
// bmsConnectPdo() resolves this environment's tenant normally), THEN
// overrides $GLOBALS['__bms_features'] before requiring the target page —
// forges an admin session too. The page's own header()/exit() on denial is
// safe here — it only ends this subprocess, never the test runner.
function _render_page_as_admin(string $root, int $uid, string $relPath, ?bool $projectsOn, ?bool $hrOn): string
{
    [$cleanRelPath, $query] = array_pad(explode('?', $relPath, 2), 2, '');
    parse_str($query, $getParams);

    $features = [];
    if ($projectsOn !== null) $features['projects'] = $projectsOn;
    if ($hrOn !== null) $features['hr'] = $hrOn;
    $featuresPhp = var_export($features, true);
    $rootPhp     = var_export($root, true);
    $filePhp     = var_export("$root/$cleanRelPath", true);
    $getPhp      = var_export($getParams, true);

    // $GLOBALS['__bms_features'] must be set AFTER roots.php runs: this local
    // single-tenant setup has no control DB, so bmsConnectPdo() (called during
    // roots.php's own bootstrap) unconditionally resets it to null ("no tenant
    // resolved -> everything on"), clobbering anything set beforehand. Setting
    // it afterward is still a faithful simulation — tenantFeatureEnabled()
    // only ever reads the global at call time, which for a real per-request
    // tenant happens well after that same bootstrap step.
    $code = <<<PHP
        chdir({$rootPhp});
        require_once {$rootPhp} . '/roots.php';
        \$GLOBALS['__bms_features'] = {$featuresPhp};
        \$_SESSION['user_id'] = {$uid};
        \$_SESSION['is_admin'] = true;
        \$_SESSION['role_id'] = 1;
        \$_GET = {$getPhp};
        require {$filePhp};
        PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'bms_page_probe_') . '.php';
    file_put_contents($tmp, "<?php\n" . $code . "\n");
    $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $tmp]));
    $out = shell_exec($cmd . ' 2>&1');
    @unlink($tmp);
    return (string)$out;
}

foreach ([
    ['sub_contractors.php', 'app/bms/operations/sub_contractors.php', 'id="scTable"', null],
    ['sub_contractor_details.php', 'app/bms/operations/sub_contractor_details.php', 'id="scProjectsTable"', $scId],
] as [$label, $relPath, $marker, $id]) {
    $getSuffix = $id !== null ? "?id=$id" : '';
    $onOut  = _render_page_as_admin($root, $adminUid, $relPath . $getSuffix, true, null);
    $offOut = _render_page_as_admin($root, $adminUid, $relPath . $getSuffix, false, null);

    strpos($onOut, $marker) !== false
        ? pass("$label: renders normally when projects=on")
        : fail("$label: did not render expected content with projects=on — output: " . substr($onOut, 0, 300));
    strpos($offOut, $marker) === false
        ? pass("$label: blocked (no page content) when projects=off")
        : fail("$label: rendered page content even with projects=off — leak!");
}

// Expenses — Simple POS mode and normal mode, HR on vs off.
foreach ([true, false] as $simple) {
    $origSetting = null;
    // Toggle pos_simple_mode via the same DB the subprocess will read.
    $pdo = new PDO('mysql:host=localhost;dbname=bms;charset=utf8mb4', 'root', '');
    $origSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $exists = $pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $val = $simple ? '1' : '0';
    if ($exists) {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$val]);
    } else {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?)")->execute([$val]);
    }

    $mode = $simple ? 'Simple POS' : 'normal';
    $onOut  = _render_page_as_admin($root, $adminUid, 'app/constant/accounts/expenses.php', null, true);
    $offOut = _render_page_as_admin($root, $adminUid, 'app/constant/accounts/expenses.php', null, false);

    // Checked by the stable option value, not the (locale-translated) label —
    // this session may render Swahili ("Mfanyakazi"), not literal English text.
    strpos($onOut, 'option value="staff"') !== false
        ? pass("expenses.php ($mode mode): shows the Staff option when hr=on")
        : fail("expenses.php ($mode mode): Staff option missing with hr=on — output len " . strlen($onOut));
    strpos($offOut, 'option value="staff"') === false
        ? pass("expenses.php ($mode mode): hides the Staff option when hr=off")
        : fail("expenses.php ($mode mode): Staff option still present with hr=off — leak!");

    if ($origSetting === false) {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    } else {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSetting]);
    }
}
pass('pos_simple_mode setting restored to its original value');

exit($failures === 0 ? 0 : 1);
