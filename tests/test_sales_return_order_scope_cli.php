<?php
/**
 * Sales Return creation — sales order project-scope guard (behavioural)
 *   php tests/test_sales_return_order_scope_cli.php
 *
 * api/sales/search_orders.php (the order picker used by
 * sales_return_create.php's "search by order number or customer") queried
 * every sales order in the system unfiltered — a non-admin could find and
 * select any order, not just ones tied to their assigned projects. Its own
 * comment falsely claimed "SO scope enforced at sales_orders list level",
 * but this is an independent query the list page's filter never touched.
 * api/sales/create_return.php also had no scope check on the submitted
 * sales_order_id, unlike the pattern used elsewhere (assertScopeForRecord).
 *
 * This test verifies: the search query only returns a non-admin's in-scope
 * orders, and the create endpoint rejects a hand-crafted request naming an
 * order outside that scope. Read-only for the denial path (it exits before
 * any write); no fixtures, no rollback needed.
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

// ── Child mode ──────────────────────────────────────────────────────────
// Runs the real create_return.php as a real non-admin session. Own process
// because the endpoint exit()s on the denial branch.
//   argv: --render-child <user_id> <role_id> <sales_order_id>
if (($argv[1] ?? '') === '--render-child') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/bms/api/sales/create_return.php';
    $_SERVER['SCRIPT_NAME']    = '/bms/index.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/roots.php';

    $uid = (int)$argv[2]; $rid = (int)$argv[3];
    $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = $rid; $_SESSION['is_admin'] = false;
    loadUserScope($uid);
    if (function_exists('loadUserPermissions')) loadUserPermissions($rid);

    $_POST = [
        'sales_order_id' => (int)$argv[4],
        'customer_id'    => 1,
        'items'          => [1 => 1],
    ];

    ob_start();
    register_shutdown_function(function () {
        $out = ob_get_clean();
        file_put_contents('php://stdout', "\n__RESULT__" . $out . "\n");
    });
    include dirname(__DIR__) . '/api/sales/create_return.php';
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

function runChild(int $uid, int $rid, int $orderId): ?array {
    $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --render-child ' . $uid . ' ' . $rid . ' ' . $orderId . " 2>$devnull";
    $out = shell_exec($cmd) ?: '';
    if (!preg_match('/__RESULT__(\{.*\})\s*$/s', $out, $m)) return null;
    return json_decode($m[1], true);
}

echo "\n\033[1m═══ Sales Return creation — sales order scope guard ═══\033[0m\n";

head('Source — search endpoint is scope-filtered, no longer claims a false guarantee');
$src = @file_get_contents(dirname(__DIR__) . '/api/sales/search_orders.php') ?: '';
(strpos($src, "scope-audit: skip") === false)
    ? ok('the misleading scope-audit skip marker is gone (file now genuinely scope-filters)')
    : bad('still carries the scope-audit skip marker — should use real filtering instead');
(strpos($src, "scopeFilterSqlNullable('project', 'so')") !== false && strpos($src, "scopeFilterSqlNullable('warehouse', 'so')") !== false)
    ? ok('search query calls scopeFilterSqlNullable for both project and warehouse')
    : bad('search query is missing a scope filter');

head('Source — create endpoint gates the submitted sales_order_id');
$src2 = @file_get_contents(dirname(__DIR__) . '/api/sales/create_return.php') ?: '';
(strpos($src2, "assertScopeForRecord('sales_orders'") !== false)
    ? ok('create_return.php calls assertScopeForRecord on the submitted order')
    : bad('create_return.php has no scope gate on sales_order_id');

head('Syntax — both files parse cleanly');
foreach (['api/sales/search_orders.php', 'api/sales/create_return.php'] as $rel) {
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(dirname(__DIR__) . '/' . $rel) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok("$rel — no syntax errors")
        : bad("$rel — " . trim((string)$res));
}

head("END-TO-END — search query only returns a real non-admin's in-scope orders");
$user = $pdo->query("
    SELECT u.user_id, (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) pcount
    FROM users u
    WHERE u.role_id != 1
      AND (SELECT COUNT(*) FROM user_projects up WHERE up.user_id=u.user_id) > 0
    ORDER BY pcount DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "  \033[33m—\033[0m skipped: no non-admin with a project assignment found\n";
} else {
    $uid = (int)$user['user_id'];
    $_SESSION['user_id'] = $uid; loadUserScope($uid);
    $myProjects = array_map('intval', $_SESSION['scope']['projects'] ?? []);

    $rows = $pdo->query("
        SELECT so.sales_order_id, so.project_id
        FROM sales_orders so
        WHERE so.status IN ('approved','completed')
        " . scopeFilterSqlNullable('project', 'so') . scopeFilterSqlNullable('warehouse', 'so') . "
    ")->fetchAll(PDO::FETCH_ASSOC);

    $leaked = array_filter($rows, fn($r) => $r['project_id'] !== null && !in_array((int)$r['project_id'], $myProjects, true));
    empty($leaked)
        ? ok("all " . count($rows) . " order(s) returned to user #$uid are NULL-project or one of their own")
        : bad('search leaked ' . count($leaked) . ' out-of-scope order(s): ' . json_encode(array_values($leaked)));
}

head('END-TO-END — live create_return.php rejects an out-of-scope sales_order_id');
$order = $pdo->query("SELECT sales_order_id, project_id FROM sales_orders WHERE project_id IS NOT NULL AND status IN ('approved','completed') LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "  \033[33m—\033[0m skipped: no project-tagged sales order found in this DB\n";
} else {
    $oid = (int)$order['sales_order_id']; $oProject = (int)$order['project_id'];
    $outsider = $pdo->prepare("
        SELECT u.user_id, u.role_id
        FROM users u
        JOIN role_permissions rp ON rp.role_id=u.role_id
        JOIN permissions pm ON pm.permission_id=rp.permission_id
        WHERE u.role_id != 1 AND pm.page_key='sales_returns' AND rp.can_create=1
          AND u.user_id NOT IN (SELECT user_id FROM user_projects WHERE project_id=?)
          AND u.user_id NOT IN (SELECT user_id FROM user_scope_overrides WHERE resource_type='project')
        LIMIT 1
    ");
    $outsider->execute([$oProject]);
    $u = $outsider->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo "  \033[33m—\033[0m skipped: no non-admin with create permission and no access to project #$oProject found\n";
    } else {
        $res = runChild((int)$u['user_id'], (int)$u['role_id'], $oid);
        ($res !== null && ($res['success'] ?? true) === false && stripos($res['message'] ?? '', 'project') !== false)
            ? ok("rejected user #{$u['user_id']} (no access to project #$oProject) submitting order #$oid — " . $res['message'])
            : bad('did NOT reject the out-of-scope order submission: ' . json_encode($res));
    }
}
