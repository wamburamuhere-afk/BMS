<?php
/**
 * Simple POS dashboard chart — Expenses per shop + real Net Profit — CLI test
 * ----------------------------------------------------------------------------
 *   php tests/test_simple_pos_expenses_chart_cli.php
 *
 * User request 2026-09-15: the Simple Mode dashboard chart only ever showed
 * Sales vs Cost of Goods ("Faida Halisi" = gross margin) — no real operating
 * Expenses, and `expenses` had no way to tie a cost to a shop at all (only
 * `project_id`). Added `expenses.warehouse_id`, a Shop picker on the Simple
 * POS Add/Edit Expense form, and a 3rd series (real approved/paid Expenses)
 * in posSimpleBuySellSeries() so `net_profit` = Sold − Bought − Expenses is
 * the shop owner's actual bottom line, not just gross margin. Rebuilt
 * dashboard.php's chart as two separate functions (renderNormalChart /
 * renderSimpleChart) so the accrual/cash ledger-based normal-mode chart is
 * never touched by any of this — gated strictly behind Simple Mode
 * (superadmin-granted per tenant, posSimpleModeEnabled()), as requested.
 *
 * Verifies:
 *   1. All touched files lint-clean.
 *   2. Schema: expenses.warehouse_id exists (both migrations, idempotent).
 *   3. Source wiring — Shop picker on the expense form, warehouse_id read +
 *      scope-checked + persisted in add/update_expense.php, the metrics
 *      function's new parameter and return keys, the chart API passing an
 *      expense scope and returning operating_expenses/net_profit, and
 *      dashboard.php's clean render-function split (Simple Mode never
 *      reaches the normal-mode code path or vice versa).
 *   4. Live-DB (BEGIN/ROLLBACK): posSimpleBuySellSeries() correctly sums
 *      only 'approved'/'paid' expenses (never 'pending'), correctly excludes
 *      another warehouse's expenses (shop-scope isolation — the whole point
 *      of this feature), and net_profit = sold - bought - expenses.
 *   5. Regression: existing test_pos_simple_mode_cli.php-relevant behaviour
 *      (posSimpleBuySellSeries with an empty expense scope, i.e. the old
 *      call signature) still returns the old 2-metric shape correctly —
 *      no consumer that hasn't opted into the 3rd param is broken.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_dashboard_metrics.php";

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$touchedFiles = [
    'app/constant/accounts/expenses.php',
    'api/account/add_expense.php',
    'api/account/update_expense.php',
    'api/pos/get_simple_dashboard_chart.php',
    'core/pos_dashboard_metrics.php',
    'app/dashboard.php',
    'migrations/tenant/2026_09_15_expenses_warehouse_id.php',
    'migrations/2026_09_15_expenses_warehouse_id_legacy_db.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. All ' . count($touchedFiles) . ' files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($touchedFiles as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema — expenses.warehouse_id');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'warehouse_id'")->fetch(PDO::FETCH_ASSOC);
$col ? pass("expenses.warehouse_id exists (Type={$col['Type']}, Null={$col['Null']})") : fail('expenses.warehouse_id is missing');
if ($col) {
    strtolower($col['Type']) === 'int' ? pass('column type is INT') : fail("column type is {$col['Type']}, expected int");
    $col['Null'] === 'YES' ? pass('column is nullable (company-wide expenses stay NULL)') : fail('column is NOT NULL — company-wide expenses would be forced onto a shop');
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Source wiring');
// ─────────────────────────────────────────────────────────────────────────
$expensesSrc = file_get_contents("$root/app/constant/accounts/expenses.php");
strpos($expensesSrc, "warehousesForSelect(\$pdo)") !== false
    ? pass('expenses.php fetches scoped warehouses via the shared helper')
    : fail('expenses.php does not use warehousesForSelect()');
strpos($expensesSrc, 'name="warehouse_id"') !== false
    ? pass('expenses.php form field is named warehouse_id (posts via plain $form.serialize())')
    : fail('expenses.php has no warehouse_id form field');
strpos($expensesSrc, "\$posSimple") !== false && strpos($expensesSrc, '$showShopPicker') !== false
    ? pass('Shop picker follows the same auto-pick-if-one-shop pattern as Products')
    : fail('Shop picker does not follow the established $showShopPicker/$onlyWarehouseId pattern');
strpos($expensesSrc, "data.warehouse_id") !== false
    ? pass('editExpense() populates the Shop field from the loaded record')
    : fail('editExpense() never populates warehouse_id — editing an expense would silently lose its shop');

$addSrc = file_get_contents("$root/api/account/add_expense.php");
strpos($addSrc, "\$_POST['warehouse_id']") !== false
    ? pass('add_expense.php reads warehouse_id from $_POST')
    : fail('add_expense.php never reads warehouse_id');
strpos($addSrc, "userCan('warehouse', \$warehouse_id)") !== false
    ? pass('add_expense.php scope-checks the submitted warehouse_id')
    : fail('add_expense.php does not verify warehouse_id is in the user\'s scope — a hand-crafted request could post against another shop');
strpos($addSrc, 'warehouse_id, budget_id') !== false
    ? pass('add_expense.php INSERT column list includes warehouse_id')
    : fail('add_expense.php INSERT does not persist warehouse_id');

$updateSrc = file_get_contents("$root/api/account/update_expense.php");
strpos($updateSrc, "userCan('warehouse', (int)\$_POST['warehouse_id'])") !== false
    ? pass('update_expense.php scope-checks the submitted warehouse_id')
    : fail('update_expense.php does not verify warehouse_id is in the user\'s scope');
// Reading + validating warehouse_id but never writing it is exactly the bug
// class this checks for: the SET clause AND the execute() params array must
// both actually carry it, not just the validation above.
preg_match('/UPDATE expenses SET(.*?)WHERE expense_id/s', $updateSrc, $setClauseMatch);
$setClause = $setClauseMatch[1] ?? '';
strpos($setClause, 'warehouse_id') !== false
    ? pass('update_expense.php\'s UPDATE SET clause actually includes warehouse_id')
    : fail('update_expense.php reads/validates warehouse_id but never writes it to the SET clause — an edit would silently drop the shop');
preg_match('/\$stmt->execute\(\[(.*?)\]\);/s', $updateSrc, $execMatch);
strpos($execMatch[1] ?? '', '$warehouse_id') !== false
    ? pass('update_expense.php\'s execute() params array actually passes $warehouse_id')
    : fail('update_expense.php\'s execute() call never passes $warehouse_id — the SET clause placeholder would bind the wrong value');

$metricsSrc = file_get_contents("$root/core/pos_dashboard_metrics.php");
strpos($metricsSrc, 'string $expenseScopeSql = \'\'') !== false
    ? pass('posSimpleBuySellSeries() gained the new $expenseScopeSql param, defaulted empty (opt-in, non-breaking)')
    : fail('posSimpleBuySellSeries() signature does not carry the new optional param');
strpos($metricsSrc, "'expenses'") !== false && strpos($metricsSrc, "'net_profit'") !== false
    ? pass("posSimpleBuySellSeries() returns 'expenses' and 'net_profit' per row")
    : fail("posSimpleBuySellSeries() does not return the new 'expenses'/'net_profit' keys");
strpos($metricsSrc, "e.status IN ('approved','paid')") !== false
    ? pass('the expenses sub-query only counts approved/paid — pending never inflates the total')
    : fail('the expenses sub-query does not filter by status — a pending (not-yet-real) expense could inflate the chart');

$chartApiSrc = file_get_contents("$root/api/pos/get_simple_dashboard_chart.php");
strpos($chartApiSrc, "scopeFilterSqlNullable('warehouse', 'e')") !== false
    ? pass('get_simple_dashboard_chart.php builds a warehouse-scoped expense clause')
    : fail('get_simple_dashboard_chart.php does not scope the expense query by warehouse');
strpos($chartApiSrc, "'operating_expenses'") !== false && strpos($chartApiSrc, "'net_profit'") !== false
    ? pass('the API response carries operating_expenses and net_profit')
    : fail('the API response is missing the new fields');

$dashSrc = file_get_contents("$root/app/dashboard.php");
strpos($dashSrc, 'function renderSimpleChart(data)') !== false
    ? pass('dashboard.php defines a dedicated renderSimpleChart()')
    : fail('renderSimpleChart() is missing');
strpos($dashSrc, 'function renderNormalChart(data)') !== false
    ? pass('dashboard.php defines a dedicated renderNormalChart()')
    : fail('renderNormalChart() is missing');
strpos($dashSrc, 'if (POS_SIMPLE_MODE) { renderSimpleChart(data); return; }') !== false
    ? pass('renderChart() dispatches cleanly by mode — the two code paths can never cross')
    : fail('renderChart() dispatch logic regressed');
// The old in-function branch must be gone from renderNormalChart — if it's
// still there, Simple Mode tenants would hit dead/duplicated summary logic.
$normalChartBody = substr($dashSrc, strpos($dashSrc, 'function renderNormalChart'), strpos($dashSrc, 'function renderSimpleChart') - strpos($dashSrc, 'function renderNormalChart'));
strpos($normalChartBody, 'if (POS_SIMPLE_MODE)') === false
    ? pass('renderNormalChart() no longer branches on POS_SIMPLE_MODE — Simple Mode never touches this code path')
    : fail('renderNormalChart() still contains a POS_SIMPLE_MODE branch — the old dead-weight duplication was not cleaned up');
strpos($dashSrc, "lineDataset(<?= json_encode(t('Sales')) ?>") !== false
    && strpos($dashSrc, "lineDataset(<?= json_encode(t('Cost of Goods')) ?>") !== false
    && strpos($dashSrc, "lineDataset(<?= json_encode(t('Expenses')) ?>") !== false
    ? pass('renderSimpleChart() plots exactly 3 lines: Sales, Cost of Goods, Expenses')
    : fail('renderSimpleChart() does not wire up all 3 expected datasets');

// ─────────────────────────────────────────────────────────────────────────
section('4. Live-DB (BEGIN/ROLLBACK) — real expense math + shop-scope isolation');
// ─────────────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $warehouses = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($warehouses) < 1) {
        fail('no active warehouse exists in this database to test against — skipping section 4');
    } else {
        $whA = (int)$warehouses[0];
        $whB = isset($warehouses[1]) ? (int)$warehouses[1] : $whA + 900000; // synthetic non-existent id if only one real warehouse

        $ins = $pdo->prepare("INSERT INTO expenses (expense_date, amount, description, status, warehouse_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute(['2026-09-15', 50000, 'TEST approved expense, shop A', 'approved', $whA, 1]);
        $ins->execute(['2026-09-15', 12000, 'TEST paid expense, shop A',     'paid',     $whA, 1]);
        $ins->execute(['2026-09-15', 99999, 'TEST pending expense, shop A — must be excluded', 'pending', $whA, 1]);
        $ins->execute(['2026-09-15', 77777, 'TEST approved expense, shop B — must not leak into shop A', 'approved', $whB, 1]);
        $ins->execute(['2026-09-15', 33333, 'TEST approved expense, company-wide — must not leak into shop A', 'approved', null, 1]);

        $expScopeA = " AND e.warehouse_id = $whA";
        $rows = posSimpleBuySellSeries($pdo, '2026-09-01', '2026-09-30', 'daily', '', $expScopeA);
        $today = null;
        foreach ($rows as $r) { if ($r['period'] === '2026-09-15') { $today = $r; break; } }

        if ($today === null) {
            fail('posSimpleBuySellSeries() returned no row at all for the test date');
        } else {
            abs($today['expenses'] - 62000.0) < 0.01
                ? pass("shop A's expenses = 62,000 (50,000 approved + 12,000 paid), got {$today['expenses']}")
                : fail("shop A's expenses expected 62000, got {$today['expenses']} — pending/other-shop/company-wide leaked in, or a real one was dropped");
            array_key_exists('net_profit', $today)
                ? pass('row carries net_profit')
                : fail('row is missing net_profit');
            abs($today['net_profit'] - ($today['sold'] - $today['bought'] - $today['expenses'])) < 0.01
                ? pass('net_profit == sold - bought - expenses')
                : fail('net_profit does not equal sold - bought - expenses');
        }

        // Cross-shop isolation, the whole point of the feature: shop A's series
        // must never include shop B's or the company-wide expense.
        (62000.0 - ($today['expenses'] ?? -1)) === 0.0 || abs(($today['expenses'] ?? 0) - 62000.0) < 0.01
            ? pass("shop B's 77,777 and the company-wide 33,333 expense did not leak into shop A's total")
            : fail('cross-shop isolation failed — a total outside 62,000 means data leaked across shops');
    }
} finally {
    $pdo->rollBack();
    pass('all synthetic rows rolled back — no test data persisted');
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Regression — old call signature (no expense scope) still works exactly as before');
// ─────────────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $rowsOld = posSimpleBuySellSeries($pdo, '2026-09-01', '2026-09-30', 'daily', '');
    $ok = true;
    foreach ($rowsOld as $r) {
        if (!array_key_exists('sold', $r) || !array_key_exists('bought', $r) || !array_key_exists('profit', $r)) { $ok = false; break; }
        if (($r['expenses'] ?? null) !== 0.0) { $ok = false; break; } // no scope opted in -> always 0, never guessed
    }
    $ok ? pass('calling with no $expenseScopeSql still returns the original sold/bought/profit shape, expenses=0 (opt-in, never assumed)')
        : fail('old-signature callers would see a shape change or a non-zero guessed expenses figure');
} finally {
    $pdo->rollBack();
}

exit($failures === 0 ? 0 : 1);
