<?php
/**
 * Stock Adjustments — project-scope guard (behavioural)
 *   php tests/test_stock_adjustment_project_scope_cli.php
 *
 * app/bms/stock/stock_adjustments.php's "New Adjustment" Project dropdown was
 * built from an unfiltered `SELECT * FROM projects` — every non-admin saw and
 * could pick every project in the system, not just their assigned ones (the
 * Warehouse dropdown on the same page already used a scope-aware helper).
 * api/create_stock_adjustment.php and api/update_adjustment.php also had no
 * userCan('project', ...) gate on the submitted project_id, unlike the
 * existing userCan('warehouse', ...) check right next to it.
 *
 * This test verifies: the dropdown query only returns a non-admin's assigned
 * projects, and both API endpoints reject a hand-crafted request naming a
 * project outside that scope. Read-only for the denial path (it exits before
 * any write); no fixtures, no rollback needed.
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

// ── Child mode ──────────────────────────────────────────────────────────
// Runs the real API endpoint as a real non-admin session. Own process
// because these scripts exit() on a denial branch.
//   argv: --render-child <user_id> <role_id> <product_id> <warehouse_id> <project_id> <endpoint>
if (($argv[1] ?? '') === '--render-child') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/bms/api/create_stock_adjustment.php';
    $_SERVER['SCRIPT_NAME']    = '/bms/index.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/roots.php';

    $uid = (int)$argv[2]; $rid = (int)$argv[3];
    $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = $rid; $_SESSION['is_admin'] = false;
    loadUserScope($uid);
    if (function_exists('loadUserPermissions')) loadUserPermissions($rid);

    $_POST = [
        'product_id'    => (int)$argv[4],
        'warehouse_id'  => (int)$argv[5],
        'quantity'      => 1,
        'movement_type' => 'adjustment_in',
        'reason'        => 'scope-guard test',
        'project_id'    => (int)$argv[6],
    ];

    ob_start();
    register_shutdown_function(function () {
        $out = ob_get_clean();
        file_put_contents('php://stdout', "\n__RESULT__" . $out . "\n");
    });
    $endpoint = ($argv[7] ?? 'create') === 'update'
        ? '/api/update_adjustment.php'
        : '/api/create_stock_adjustment.php';
    if ($endpoint === '/api/update_adjustment.php') {
        $_POST['movement_id'] = 999999999; // won't be found, but the scope gate runs first
    }
    include dirname(__DIR__) . $endpoint;
    exit(0);
}

require_once dirname(__DIR__) . '/roots.php';

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$passes\033[0m\nFailures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});

function runChild(array $argv): ?array {
    $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --render-child';
    foreach ($argv as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $cmd .= " 2>$devnull";
    $out = shell_exec($cmd) ?: '';
    if (!preg_match('/__RESULT__(\{.*\})\s*$/s', $out, $m)) return null;
    return json_decode($m[1], true);
}

echo "\n\033[1m═══ Stock Adjustments — project-scope guard ═══\033[0m\n";

head('Source — dropdown query is scope-filtered');
$src = @file_get_contents(dirname(__DIR__) . '/app/bms/stock/stock_adjustments.php') ?: '';
(strpos($src, "scopeFilterSql('project', 'projects')") !== false)
    ? ok('project dropdown query calls scopeFilterSql')
    : bad('project dropdown query has no scope filter');

head('Source — both endpoints gate the submitted project_id');
foreach (['api/create_stock_adjustment.php', 'api/update_adjustment.php'] as $rel) {
    $s = @file_get_contents(dirname(__DIR__) . '/' . $rel) ?: '';
    (strpos($s, "userCan('project'") !== false)
        ? ok("$rel checks userCan('project', ...)")
        : bad("$rel is missing a userCan('project', ...) gate");
}

head('Syntax — all 3 files parse cleanly');
foreach ([
    'app/bms/stock/stock_adjustments.php',
    'api/create_stock_adjustment.php',
    'api/update_adjustment.php',
] as $rel) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/' . $rel) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok("$rel — no syntax errors")
        : bad("$rel — " . trim((string)$res));
}

head('END-TO-END — dropdown only returns a real non-admin\'s assigned projects');
$user = $pdo->query("
    SELECT u.user_id, u.role_id, (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) pcount
    FROM users u JOIN roles r ON u.role_id=r.role_id
    JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions pm ON pm.permission_id=rp.permission_id
    WHERE u.role_id != 1 AND pm.page_key='products' AND rp.can_edit=1
      AND (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) > 0
    ORDER BY pcount DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "  \033[33m—\033[0m skipped: no non-admin with products-edit permission and a project assignment found\n";
} else {
    $uid = (int)$user['user_id']; $rid = (int)$user['role_id'];
    $_SESSION['user_id'] = $uid; loadUserScope($uid);
    $myProjects = array_map('intval', $_SESSION['scope']['projects'] ?? []);

    $totalProjects = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status!='cancelled'")->fetchColumn();
    $scoped = $pdo->query("SELECT project_id FROM projects WHERE status!='cancelled' " . scopeFilterSql('project', 'projects'))->fetchAll(PDO::FETCH_COLUMN);
    $scoped = array_map('intval', $scoped);

    (count($scoped) <= $totalProjects) ? ok("scoped list ({" . count($scoped) . "}) is not larger than total ($totalProjects)") : bad('scoped list exceeds total — impossible');
    (empty(array_diff($scoped, $myProjects)))
        ? ok("every project the dropdown shows user #$uid is one they're actually assigned to")
        : bad('dropdown leaked a project outside this user\'s assignment: ' . json_encode(array_diff($scoped, $myProjects)));

    $outOfScope = (int)$pdo->query("SELECT project_id FROM projects WHERE status!='cancelled' AND project_id NOT IN (" . (implode(',', $myProjects) ?: '0') . ") LIMIT 1")->fetchColumn();

    head("END-TO-END — live API rejects an out-of-scope project_id (user #$uid)");
    if (!$outOfScope) {
        echo "  \033[33m—\033[0m skipped: this user is assigned to every project in the DB, nothing out-of-scope to test with\n";
    } else {
        $prod = (int)$pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetchColumn();
        $wh   = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn();

        foreach (['create', 'update'] as $mode) {
            $res = runChild([$uid, $rid, $prod, $wh, $outOfScope, $mode]);
            if ($res === null) {
                bad("$mode: child process produced no parseable JSON");
                continue;
            }
            ($res['success'] ?? true) === false && stripos($res['message'] ?? '', 'project') !== false
                ? ok("$mode: rejected out-of-scope project #$outOfScope — " . $res['message'])
                : bad("$mode: did NOT reject out-of-scope project — " . json_encode($res));
        }
    }
}
