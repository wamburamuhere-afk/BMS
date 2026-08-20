<?php
/**
 * tests/test_project_view_render_cli.php
 * Renders app/bms/operations/project_view.php (22k-line page, JS split across
 * includes/project_view/scripts/pv_js_*.php as of the 2026-08-20 JS-extraction
 * refactor) in admin, sub-contractor-mode, and supplier-mode, asserting each
 * renders with no fatal/parse/SQL error and the key markers are present.
 *
 *   php tests/test_project_view_render_cli.php
 */
$root = dirname(__DIR__);

if (in_array($argv[1] ?? '', ['render'], true)) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin'; $_SESSION['user_role'] = 'Admin';
    $_SESSION['first_name'] = 'Test'; $_SESSION['last_name'] = 'Admin';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/project_view';
    $_GET['id'] = (int)($argv[2] ?? 0);
    if (!empty($argv[3])) $_GET['sc_id'] = (int)$argv[3];
    if (!empty($argv[4])) $_GET['supplier_id'] = (int)$argv[4];
    require "$root/app/bms/operations/project_view.php";
    exit;
}

require_once "$root/roots.php";
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function render($root, $id, $sc = 0, $sup = 0) { return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . " render $id $sc $sup 2>&1"); }
function noErr($html) { foreach (['Fatal error','Parse error','Uncaught','Unknown column','SQLSTATE','Call to a member function','include(): Failed opening'] as $e) if (stripos($html,$e)!==false) return false; return true; }

echo "── lint main file + all extracted JS includes ──────────────────────────\n";
$rc=0; exec("php -l ".escapeshellarg("$root/app/bms/operations/project_view.php")." 2>&1",$o,$rc);
ok($rc===0, 'lint app/bms/operations/project_view.php');
foreach (glob("$root/includes/project_view/scripts/pv_js_*.php") as $f) {
    $o2=[]; $rc2=0; exec("php -l ".escapeshellarg($f)." 2>&1",$o2,$rc2);
    ok($rc2===0, 'lint ' . basename($f));
}

echo "\n── pick real test project ids ──────────────────────────────────────────\n";
$projId = (int)$pdo->query("SELECT project_id FROM projects WHERE status != 'deleted' ORDER BY project_id LIMIT 1")->fetchColumn();
ok($projId > 0, "found a project to render (id=$projId)");
$scRow  = $pdo->query("SELECT project_id, supplier_id FROM sub_contractor_projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$supRow = $pdo->query("SELECT project_id, supplier_id FROM supplier_projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "\n── admin mode renders cleanly ──────────────────────────────────────────\n";
$html = render($root, $projId);
ok(strlen($html) > 5000, 'admin: rendered (' . strlen($html) . ' bytes)');
ok(noErr($html), 'admin: no fatal/parse/SQL/include error');
foreach (['id="overview"'=>'overview tab','id="planning"'=>'planning tab','id="sc-payments"'=>'sc-payments tab','const projectId'=>'JS config const','function renderProject'=>'JS renderProject fn','function priActions'=>'JS priActions fn'] as $n=>$l)
    ok(strpos($html,$n)!==false, "admin renders: $l");

if ($scRow) {
    echo "\n── sub-contractor (restricted) mode renders cleanly ───────────────────\n";
    $html = render($root, $scRow['project_id'], $scRow['supplier_id']);
    ok(strlen($html) > 5000, 'sc-mode: rendered (' . strlen($html) . ' bytes)');
    ok(noErr($html), 'sc-mode: no fatal/parse/SQL/include error');
}

if ($supRow) {
    echo "\n── supplier (restricted) mode renders cleanly ─────────────────────────\n";
    $html = render($root, $supRow['project_id'], 0, $supRow['supplier_id']);
    ok(strlen($html) > 5000, 'supplier-mode: rendered (' . strlen($html) . ' bytes)');
    ok(noErr($html), 'supplier-mode: no fatal/parse/SQL/include error');
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
