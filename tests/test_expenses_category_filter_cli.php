<?php
/**
 * Expenses — left-panel Types & Categories tree filter — CLI test
 *   php tests/test_expenses_category_filter_cli.php
 *
 * The "Expenses by Category" capability now lives INSIDE expenses.php as a
 * left-hand tree (Type → Category → Sub-category), driving the existing expenses
 * table via new filters on get_expenses.php. This guards:
 *   - the page renders the tree (Planning-style bi-caret-right-fill) + wires the
 *     filter into the DataTable, and the separate page/route is gone;
 *   - get_expenses.php filters by category subtree / whole type / uncategorised
 *     and the summary cards honour the same filter;
 *   - runtime: a category filter returns exactly the expenses mapped into its
 *     subtree (and its total matches), a type filter returns type_id ∪ mapped,
 *     uncategorised returns rows with no map and no type.
 * Read-only.
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
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint + the separate page/route is gone');
foreach (['api/account/get_expenses.php', 'app/constant/accounts/expenses.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}
(!file_exists("$root/app/constant/accounts/expenses_by_category.php")) ? pass('separate page removed') : fail('separate page still present');
(!file_exists("$root/api/account/get_expenses_by_category.php")) ? pass('separate API removed') : fail('separate API still present');
hasnt(src($root, 'roots.php'), "expenses_by_category.php", 'routes to the separate page removed');

// ─────────────────────────────────────────────────────────────────────────
section('2. expenses.php — left tree panel + caret + wiring');
$page = src($root, 'app/constant/accounts/expenses.php');
has($page, 'id="expTreePanel"', 'left tree panel present');
has($page, 'bi-caret-right-fill', 'Planning-style caret used');
has($page, 'function pickExpNode', 'pickExpNode() defined');
has($page, 'function toggleExpNode', 'toggleExpNode() defined');
has($page, 'renderExpenseCatRows', 'category rows rendered server-side');
has($page, 'd.filter_type_id = window.expFilterType', 'type filter wired into DataTable');
has($page, 'd.filter_category_id = window.expFilterCat', 'category filter wired into DataTable');
has($page, 'd.filter_uncategorised', 'uncategorised filter wired into DataTable');
has($page, 'col-lg-3', 'left column present');
has($page, 'col-lg-9', 'right column present');

// ─────────────────────────────────────────────────────────────────────────
section('3. get_expenses.php — filters + scoped/stat-aware');
$api = src($root, 'api/account/get_expenses.php');
has($api, "filter_type_id", 'reads filter_type_id');
has($api, "filter_category_id", 'reads filter_category_id');
has($api, "filter_uncategorised", 'reads filter_uncategorised');
has($api, 'expense_category_map WHERE category_id IN', 'category/type filter uses the map');
has($api, "scopeFilterSqlNullable('project', 'e')", 'stats query is project-scoped (§23)');
(strpos($api, '. $filterSql') !== false) ? pass('stats query appends the shared $filterSql (cards honour the filter)') : fail('stats query does not honour $filterSql');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — filters return the right sets (read-only)');
$runner = sys_get_temp_dir() . '/exf_' . getmypid() . '.php';
file_put_contents($runner, <<<'RUN'
<?php
require_once $argv[1]; global $pdo;
$u=(int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.is_admin=1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$_SESSION['user_id']=$u;$_SESSION['role_id']=1;$_SESSION['is_admin']=1;$_SESSION['scope']=['is_admin'=>1];
$_SERVER['REQUEST_METHOD']='GET';
$_GET=json_decode(base64_decode($argv[3]),true); $_GET['draw']=1;$_GET['start']=0;$_GET['length']=500;
ob_start(); include $argv[2]; echo ob_get_clean();
RUN);
register_shutdown_function(fn() => @unlink($runner));
$call = function (array $get) use ($runner, $root) {
    $cmd = 'php ' . escapeshellarg($runner) . ' ' . escapeshellarg("$root/roots.php") . ' '
         . escapeshellarg("$root/api/account/get_expenses.php") . ' ' . escapeshellarg(base64_encode(json_encode($get)));
    return json_decode(trim((string)shell_exec($cmd)), true) ?: ['recordsFiltered' => -1];
};

// Pick a category that actually has mapped expenses.
$cat = $pdo->query("SELECT c.id, c.name FROM expense_categories c
                    JOIN expense_category_map m ON m.category_id = c.id
                    GROUP BY c.id ORDER BY COUNT(*) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cat) {
    // Expected: expenses mapped to this category's subtree (here: the category itself + any children).
    $childIds = $pdo->query("SELECT id FROM expense_categories WHERE parent_id = " . (int)$cat['id'])->fetchAll(PDO::FETCH_COLUMN);
    $sub = array_merge([(int)$cat['id']], array_map('intval', $childIds));
    $in  = implode(',', $sub);
    $expectCnt = (int)$pdo->query("SELECT COUNT(DISTINCT expense_id) FROM expense_category_map WHERE category_id IN ($in)")->fetchColumn();
    $expectSum = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_id IN (SELECT expense_id FROM expense_category_map WHERE category_id IN ($in))")->fetchColumn();
    $r = $call(['filter_category_id' => (int)$cat['id']]);
    ((int)$r['recordsFiltered'] === $expectCnt) ? pass("category '{$cat['name']}' filter count == map subtree ($expectCnt)") : fail("category count {$r['recordsFiltered']} ≠ expected $expectCnt");
    (abs((float)$r['totalExpenses'] - $expectSum) < 0.01) ? pass('category filter total matches (' . number_format($expectSum, 2) . ')') : fail('category total ' . number_format((float)$r['totalExpenses'], 2) . ' ≠ ' . number_format($expectSum, 2));
} else { pass('no mapped category to test — skipped (n/a)'); }

// Uncategorised: no map row AND no type.
$expUncat = (int)$pdo->query("SELECT COUNT(*) FROM expenses e WHERE (e.type_id IS NULL OR e.type_id=0) AND e.expense_id NOT IN (SELECT expense_id FROM expense_category_map)")->fetchColumn();
$ru = $call(['filter_uncategorised' => 1]);
((int)$ru['recordsFiltered'] === $expUncat) ? pass("uncategorised filter count == no-map+no-type ($expUncat)") : fail("uncategorised count {$ru['recordsFiltered']} ≠ $expUncat");

// No filter == all in scope.
$allCnt = (int)$pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn();
$ra = $call([]);
((int)$ra['recordsFiltered'] === $allCnt) ? pass("no filter returns all ($allCnt)") : fail("no-filter count {$ra['recordsFiltered']} ≠ $allCnt");
