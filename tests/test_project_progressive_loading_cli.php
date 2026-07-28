<?php
/**
 * BMS — Progressive/skeleton loading rollout — regression + behavioural guard
 *
 * Verifies the split of api/operations/get_project.php into a fast "core"
 * endpoint plus api/operations/get_project_inventory.php (the expensive
 * stock_movements aggregation for the Inventory tab), the shared
 * BMSSkeleton.load() helper in assets/js/loading.js, and its wiring into
 * project_view.php / lpo_view.php / purchase_order_details.php /
 * purchase_return_view.php. Read-only: no writes, no fixtures.
 *
 * Run:
 *   php tests/test_project_progressive_loading_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

// ── Child mode ────────────────────────────────────────────────────────────
// Renders the real get_project.php / get_project_inventory.php endpoints as
// a real session user. Runs in its own process because these scripts may
// exit() on a scope-denial branch. Parent consumes the JSON on the last line.
//
//   argv: --render-child <user_id> <project_id> <core|inventory>
if (($argv[1] ?? '') === '--render-child') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/bms/api/operations/get_project.php';
    $_SERVER['SCRIPT_NAME']    = '/bms/index.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/roots.php';

    $uid  = (int)($argv[2] ?? 0);
    $pid  = (int)($argv[3] ?? 0);
    $mode = ($argv[4] ?? 'core') === 'inventory' ? 'inventory' : 'core';

    $u = $pdo->prepare("SELECT u.username, u.role_id, COALESCE(r.is_admin,0) AS is_admin FROM users u LEFT JOIN roles r ON u.role_id=r.role_id WHERE u.user_id = ?");
    $u->execute([$uid]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    $_SESSION['user_id']  = $uid;
    $_SESSION['username'] = $row['username'] ?? 'test';
    $_SESSION['role_id']  = (int)($row['role_id'] ?? 0);
    $_SESSION['is_admin'] = (bool)($row['is_admin'] ?? false);
    loadUserScope($uid);

    $_GET['id'] = $pid;

    // The target endpoint may exit() early on a scope-denial branch — that's
    // exactly the path this test most needs to observe. A shutdown function
    // still fires through an exit(), unlike code placed after include().
    ob_start();
    register_shutdown_function(function () {
        $out = ob_get_clean();
        file_put_contents('php://stdout', "\n__RESULT__" . $out . "\n");
    });
    include dirname(__DIR__) . '/api/operations/' . ($mode === 'inventory' ? 'get_project_inventory.php' : 'get_project.php');
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

function renderChild(int $uid, int $pid, string $mode): ?array {
    $devnull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
         . ' --render-child ' . $uid . ' ' . $pid . ' ' . $mode . " 2>$devnull";
    $out = shell_exec($cmd) ?: '';
    if (!preg_match('/__RESULT__(\{.*\})\s*$/s', $out, $m)) return null;
    return json_decode($m[1], true);
}

echo "\n\033[1m═══ Progressive loading — project view Inventory split ═══\033[0m\n";

// ── 1. Static source checks ────────────────────────────────────────────────
head('Source — get_project.php no longer computes Inventory inline');
$coreSrc = @file_get_contents(dirname(__DIR__) . '/api/operations/get_project.php') ?: '';
(strpos($coreSrc, '"inventory" =>') === false)
    ? ok('get_project.php response no longer builds an "inventory" key')
    : bad('get_project.php still builds "inventory" inline — split incomplete');
(strpos($coreSrc, 'financial_summary') !== false && strpos($coreSrc, 'progress_analysis') !== false)
    ? ok('get_project.php still returns financial_summary + progress_analysis (cheap, correctly kept in core)')
    : bad('get_project.php lost financial_summary/progress_analysis — should have stayed in core');

head('Source — get_project_inventory.php carries the 6 stock sub-keys, scope-gated');
$invSrc = @file_get_contents(dirname(__DIR__) . '/api/operations/get_project_inventory.php') ?: '';
foreach (['purchased_items', 'sold_items', 'adjustments', 'movements', 'stock_summary', 'warehouses'] as $key) {
    strpos($invSrc, "\"$key\" =>") !== false
        ? ok("get_project_inventory.php builds \"$key\"")
        : bad("get_project_inventory.php missing \"$key\"");
}
strpos($invSrc, "userCan('project'") !== false
    ? ok('get_project_inventory.php re-checks userCan(project) independently (security.md §23)')
    : bad('get_project_inventory.php does not gate on project scope — data leak risk');

head('Source — shared BMSSkeleton.load() helper');
$loadingJs = @file_get_contents(dirname(__DIR__) . '/assets/js/loading.js') ?: '';
(strpos($loadingJs, 'function loadSection') !== false && strpos($loadingJs, 'load: loadSection') !== false)
    ? ok('loading.js exposes BMSSkeleton.load')
    : bad('loading.js does not expose BMSSkeleton.load');
(strpos($loadingJs, "showError('Network error") !== false)
    ? ok('loading.js has a guaranteed-resolve error/retry path (fixes stuck-forever skeleton)')
    : bad('loading.js has no network-error fallback');

$nodeCheck = shell_exec('node --check ' . escapeshellarg(dirname(__DIR__) . '/assets/js/loading.js') . ' 2>&1');
(trim((string)$nodeCheck) === '')
    ? ok('loading.js passes node --check (no syntax errors)')
    : bad('loading.js syntax error: ' . trim((string)$nodeCheck));

head('Source — project_view.php wired to BMSSkeleton.load + lazy Inventory tab');
$pv = @file_get_contents(dirname(__DIR__) . '/app/bms/operations/project_view.php') ?: '';
(preg_match('/function loadProjectDetails\(\)\s*\{\s*BMSSkeleton\.load/', $pv) === 1)
    ? ok('loadProjectDetails() uses BMSSkeleton.load')
    : bad('loadProjectDetails() does not use BMSSkeleton.load');
(strpos($pv, 'function loadProjectInventory') !== false)
    ? ok('loadProjectInventory() exists')
    : bad('loadProjectInventory() missing');
(preg_match('/function renderTables\(data\)[\s\S]*?renderInventory\(data\.inventory\)/', $pv) !== 1)
    ? ok('renderTables() no longer forces renderInventory(data.inventory) on initial load')
    : bad('renderTables() still calls renderInventory(data.inventory) — Inventory not decoupled');
(strpos($pv, 'shown.bs.tab.load') !== false)
    ? ok('Inventory tab has a shown.bs.tab lazy-load binding')
    : bad('Inventory tab lazy-load binding missing');
(strpos($pv, 'get_project_inventory.php') !== false)
    ? ok('project_view.php calls the new get_project_inventory.php endpoint')
    : bad('project_view.php does not reference get_project_inventory.php');

head('Source — the other 3 detail pages use BMSSkeleton.load');
$others = [
    'lpo_view.php'               => dirname(__DIR__) . '/app/bms/sales/lpo/lpo_view.php',
    'purchase_order_details.php' => dirname(__DIR__) . '/app/bms/purchase/purchase_order_details.php',
    'purchase_return_view.php'   => dirname(__DIR__) . '/app/bms/purchase/purchase_return_view.php',
];
foreach ($others as $name => $path) {
    $src = @file_get_contents($path) ?: '';
    (strpos($src, 'BMSSkeleton.load(') !== false)
        ? ok("$name uses BMSSkeleton.load")
        : bad("$name does not use BMSSkeleton.load");
    (strpos($src, 'id="loading"') !== false)
        ? ok("$name has a #loading container")
        : bad("$name is missing a #loading container");
}

// ── 2. PHP syntax sanity (belt-and-suspenders; CI/hooks also run php -l) ──
head('Syntax — modified/created PHP files parse cleanly');
foreach ([
    'api/operations/get_project.php',
    'api/operations/get_project_inventory.php',
    'app/bms/operations/project_view.php',
    'app/bms/sales/lpo/lpo_view.php',
    'app/bms/purchase/purchase_order_details.php',
    'app/bms/purchase/purchase_return_view.php',
] as $rel) {
    $full = dirname(__DIR__) . '/' . $rel;
    $res = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($full) . ' 2>&1');
    (strpos((string)$res, 'No syntax errors detected') !== false)
        ? ok("$rel — no syntax errors")
        : bad("$rel — " . trim((string)$res));
}

// ── 3. Live end-to-end: real project, real session, both endpoints ────────
head('END-TO-END — admin renders both endpoints for a real project');

$admin = (int)($pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.is_admin=1 OR u.role_id=1 LIMIT 1")->fetchColumn() ?: 0);
$project = (int)($pdo->query("SELECT project_id FROM projects ORDER BY project_id DESC LIMIT 1")->fetchColumn() ?: 0);

if (!$admin || !$project) {
    echo "  \033[33m—\033[0m skipped: no admin user or no project found in this DB\n";
} else {
    $core = renderChild($admin, $project, 'core');
    $inv  = renderChild($admin, $project, 'inventory');

    if ($core === null) {
        bad('get_project.php child process produced no parseable JSON');
    } else {
        ($core['success'] ?? false) === true
            ? ok("get_project.php (project #$project) — success:true")
            : bad('get_project.php did not return success:true: ' . ($core['message'] ?? 'unknown'));
        !array_key_exists('inventory', $core)
            ? ok('get_project.php response has no "inventory" key at runtime')
            : bad('get_project.php response STILL includes "inventory" at runtime — split leaked');
        foreach (['financial_summary', 'progress_analysis', 'sales_orders', 'invoices', 'purchase_orders'] as $k) {
            array_key_exists($k, $core) ? ok("get_project.php still returns \"$k\"") : bad("get_project.php lost \"$k\"");
        }
    }

    if ($inv === null) {
        bad('get_project_inventory.php child process produced no parseable JSON');
    } else {
        ($inv['success'] ?? false) === true
            ? ok("get_project_inventory.php (project #$project) — success:true")
            : bad('get_project_inventory.php did not return success:true: ' . ($inv['message'] ?? 'unknown'));
        $invData = $inv['inventory'] ?? null;
        if (!is_array($invData)) {
            bad('get_project_inventory.php has no "inventory" object');
        } else {
            foreach (['purchased_items', 'sold_items', 'adjustments', 'movements', 'stock_summary', 'warehouses'] as $k) {
                (isset($invData[$k]) && is_array($invData[$k]))
                    ? ok("get_project_inventory.php.inventory.\"$k\" is an array")
                    : bad("get_project_inventory.php.inventory.\"$k\" missing or not an array");
            }

            // Ground truth, computed independently of the endpoint.
            $truth = $pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT p.product_id, w.warehouse_id,
                        SUM(CASE
                            WHEN sm.movement_type IN ('purchase_in','adjustment_in','transfer_in','return_in','found','production_in') THEN sm.quantity
                            WHEN sm.movement_type IN ('sale_out','adjustment_out','transfer_out','return_out','damaged','expired','theft','production_out') THEN -sm.quantity
                            ELSE 0
                        END) AS bal
                    FROM stock_movements sm
                    JOIN products p ON sm.product_id = p.product_id
                    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
                    WHERE sm.project_id = ?
                    GROUP BY p.product_id, w.warehouse_id
                    HAVING bal != 0
                ) t
            ");
            $truth->execute([$project]);
            $truthCount = (int)$truth->fetchColumn();
            (count($invData['stock_summary']) === $truthCount)
                ? ok("stock_summary row count ($truthCount) matches an independently-computed ground truth")
                : bad("stock_summary row count " . count($invData['stock_summary']) . " != ground truth $truthCount");
        }
    }
}

head('END-TO-END — scope denial preserved for a non-admin outside the project');
$outsider = (int)($pdo->query("
    SELECT u.user_id FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE COALESCE(r.is_admin,0) = 0 AND u.role_id != 1
      AND (SELECT COUNT(*) FROM user_projects up WHERE up.user_id = u.user_id) = 0
      AND (SELECT COUNT(*) FROM user_scope_overrides uso WHERE uso.user_id = u.user_id AND uso.resource_type='project') = 0
    LIMIT 1
")->fetchColumn() ?: 0);

if (!$outsider || !$project) {
    echo "  \033[33m—\033[0m skipped: no zero-project non-admin user found in this DB\n";
} else {
    $core = renderChild($outsider, $project, 'core');
    $inv  = renderChild($outsider, $project, 'inventory');

    ($core !== null && ($core['success'] ?? true) === false)
        ? ok("get_project.php denies user #$outsider (no scope) access to project #$project")
        : bad('get_project.php did NOT deny an out-of-scope user — possible data leak');

    ($inv !== null && ($inv['success'] ?? true) === false)
        ? ok("get_project_inventory.php denies user #$outsider (no scope) access to project #$project")
        : bad('get_project_inventory.php did NOT deny an out-of-scope user — possible data leak');
}
