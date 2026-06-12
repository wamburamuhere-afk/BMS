<?php
/**
 * Expenses by Category — tree + roll-up — CLI test
 *   php tests/test_expenses_by_category_cli.php
 *
 * Guards the new "Expenses by Category" feature:
 *   - the API resolves ONE canonical leaf per expense from the live map and the
 *     tree reconciles: Σ(Type totals) + Uncategorised == true total (each expense
 *     counted once, no double-count from multi-mapped rows);
 *   - parent roll-up = own + all descendants;
 *   - drill mode returns the right expenses + subtotal for a node's subtree;
 *   - the page carries the FROZEN, status-gated action menu (Mark as Paid only at
 *     'approved'; Edit only pending/reviewed) and is project-scope-aware (§23);
 *   - the routes + the "By Category" link + ?edit deep-link are wired.
 * Read-only — runs the real endpoint, mutates nothing.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$API  = 'api/account/get_expenses_by_category.php';
$PAGE = 'app/constant/accounts/expenses_by_category.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([$API, $PAGE] as $f) {
    if (!file_exists("$root/$f")) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Routes + list wiring');
$rt = src($root, 'roots.php');
has($rt, "'expenses/by-category'", 'route expenses/by-category registered');
has($rt, "'expenses_by_category'", 'route expenses_by_category registered');
$list = src($root, 'app/constant/accounts/expenses.php');
has($list, "getUrl('expenses/by-category')", 'list page has the "By Category" button');
has($list, "urlParams.get('edit')", 'list page handles the ?edit deep-link');
has($list, "editExpense(editIdFromUrl)", 'deep-link opens the existing edit modal');

// ─────────────────────────────────────────────────────────────────────────
section('3. API contract — gated, scoped, additive');
$api = src($root, $API);
has($api, "canView('expenses')", 'API gated by canView(expenses)');
has($api, "scopeFilterSqlNullable('project', 'e')", 'API applies project scope (§23)');
has($api, 'expense_category_map', 'API reads attribution from the live map');
$apiHasMutation = preg_match('/\b(INSERT|UPDATE|DELETE)\s+(INTO\s+)?(expenses|expense_category_map|expense_categories)\b/i', $api);
$apiHasMutation ? fail('API must be read-only (found a write to expense tables)') : pass('API is read-only (no writes to expense tables)');

// ─────────────────────────────────────────────────────────────────────────
section('4. Page — frozen status-gated action menu');
$page = src($root, $PAGE);
has($page, "s==='pending'", 'menu gates on pending');
has($page, "Mark as Reviewed", 'pending → Mark as Reviewed');
has($page, "s==='reviewed'", 'menu gates on reviewed');
has($page, "Approve", 'reviewed → Approve');
has($page, "Reject", 'reviewed → Reject');
has($page, "s==='approved'", 'menu gates on approved');
has($page, "Mark as Paid", 'approved → Mark as Paid (only here)');
has($page, "/api/update_expense_status.php", 'status uses the same API as the list');
has($page, "/api/delete_expense.php", 'delete uses the same API as the list');
has($page, "S/NO", 'drill list has an S/NO first column');
// Mark as Paid must be gated behind 'approved', not shown at pending/reviewed
$paidPos = strpos($page, 'Mark as Paid');
$apprPos = strpos($page, "s==='approved'");
($paidPos !== false && $apprPos !== false && $apprPos < $paidPos)
    ? pass('Mark as Paid sits inside the approved branch')
    : fail('Mark as Paid not correctly gated behind approved');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — reconciliation + roll-up (real data, read-only)');

// The endpoint exit()s, so run it in a child process via a temp runner file
// (avoids inline-quoting issues with SQL on Windows). Returns the decoded JSON.
$runnerPath = sys_get_temp_dir() . '/ebc_runner_' . getmypid() . '.php';
file_put_contents($runnerPath, <<<'RUNNER'
<?php
require_once $argv[1];                 // roots.php
global $pdo;
$u = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.is_admin=1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
if ($u <= 0) $u = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 LIMIT 1")->fetchColumn();
$_SESSION['user_id'] = $u; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = 1; $_SESSION['scope'] = ['is_admin' => 1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = json_decode(base64_decode($argv[3]), true) ?: [];   // base64 → quote-safe through escapeshellarg on Windows
ob_start(); include $argv[2]; echo ob_get_clean();
RUNNER);
register_shutdown_function(fn() => @unlink($runnerPath));

$callEndpoint = function (string $root, array $get) use ($runnerPath): array {
    $get = array_merge(['date_from' => '2000-01-01', 'date_to' => '2100-12-31'], $get);
    $cmd = 'php ' . escapeshellarg($runnerPath) . ' '
         . escapeshellarg("$root/roots.php") . ' '
         . escapeshellarg("$root/api/account/get_expenses_by_category.php") . ' '
         . escapeshellarg(base64_encode(json_encode($get)));
    $out = shell_exec($cmd);
    return json_decode(trim((string)$out), true) ?: ['success' => false, 'raw' => $out];
};

$tree = $callEndpoint($root, ['mode' => 'tree']);

if (!$tree || empty($tree['success'])) {
    fail('tree call failed: ' . substr((string)$treeJson, 0, 120));
} else {
    $typesSum = 0.0; foreach ($tree['types'] as $t) $typesSum += (float)$t['total_spend'];
    $recomputed = round($typesSum + (float)$tree['uncategorised']['total'], 2);
    (abs($recomputed - (float)$tree['grand_total']) < 0.01)
        ? pass("Σ Types + Uncategorised == grand_total (" . number_format($tree['grand_total'], 2) . ")")
        : fail("does NOT reconcile: Σ=" . number_format($recomputed, 2) . " vs grand=" . number_format($tree['grand_total'], 2));
    (!empty($tree['reconciles'])) ? pass('API self-reports reconciles=true') : fail('API reports reconciles=false');

    // Roll-up: every parent's rollup == own + Σ children rollups.
    $okRoll = true;
    $check = function ($node) use (&$check, &$okRoll) {
        if (!empty($node['children'])) {
            $sum = (float)$node['own_spend'];
            foreach ($node['children'] as $c) { $sum += (float)$c['rollup_spend']; $check($c); }
            if (abs(round($sum, 2) - (float)$node['rollup_spend']) > 0.01) $okRoll = false;
        }
    };
    foreach ($tree['types'] as $t) foreach ($t['categories'] as $c) $check($c);
    $okRoll ? pass('every parent rollup == own + Σ children (roll-up correct)') : fail('a parent rollup ≠ own + children');

    // Grand total must equal SUM of all expenses in range (each once).
    $trueTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
    (abs($trueTotal - (float)$tree['grand_total']) < 0.01)
        ? pass('grand_total == SUM(expenses.amount), no double-count')
        : fail('grand_total ' . number_format($tree['grand_total'], 2) . ' ≠ SUM ' . number_format($trueTotal, 2));
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — drill subtotal matches a type total');
if ($tree && !empty($tree['success']) && !empty($tree['types'])) {
    // pick the biggest-spend type with spend > 0
    $target = null; foreach ($tree['types'] as $t) { if ((float)$t['total_spend'] > 0) { $target = $t; break; } }
    if (!$target) { pass('no spend in any type — drill check skipped (n/a)'); }
    else {
        $drill = $callEndpoint($root, ['mode' => 'drill', 'node_type' => 'type', 'node_id' => (int)$target['type_id']]);
        if (!$drill || empty($drill['success'])) fail('drill call failed: ' . substr(json_encode($drill), 0, 120));
        else {
            ($drill['node']['name'] === $target['name']) ? pass("drill node name correct ({$target['name']})") : fail("drill node name wrong: {$drill['node']['name']}");
            (abs((float)$drill['subtotal'] - (float)$target['total_spend']) < 0.01)
                ? pass('drill subtotal == the type total (' . number_format($drill['subtotal'], 2) . ')')
                : fail('drill subtotal ' . number_format($drill['subtotal'], 2) . ' ≠ type total ' . number_format($target['total_spend'], 2));
        }
    }
}
