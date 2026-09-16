<?php
/**
 * expenses.php list — default sort order + card/table resize sync
 *   php tests/test_expenses_table_sort_and_resize_cli.php
 *
 * Background: user reported that after adding a new Simple-POS expense, older
 * rows "disappeared" and the new one seemed to "appear in two rows". DB
 * inspection ruled out a duplicate INSERT (add_expense.php does exactly one).
 * Two real, confirmed gaps in assets/js/tables/bms-expenses-table.js:
 *
 *   1. DataTable() was initialised with no `order` option. Column 0 (S/NO) is
 *      orderable:false; with no explicit order, DataTables' undocumented
 *      behaviour on a non-orderable first column is inconsistent ordering —
 *      a brand-new expense (today's date) could land off page 1 while the
 *      page a user is looking at appears unchanged/"missing" recent entries.
 *   2. The desktop-table / mobile-card visibility split (renderCards) was
 *      only re-evaluated in drawCallback — never on window resize/rotation —
 *      so at a borderline viewport width the same expense could render in
 *      both the table row AND its mobile-card twin at once.
 *
 * Fix: explicit `order: [[dateColIdx, 'desc']]` (found by column *key*, not a
 * hardcoded index, so it survives the Simple-POS hide list); a
 * `resize.<tableId>` handler that re-runs renderCards off the data DataTables
 * already has in memory.
 *
 *   A. STATIC  — the JS file has valid syntax (`node --check`).
 *   B. WIRING  — source contains the dateColIdx lookup, the order option, and
 *                the resize handler, gated on cardContainer.
 *   C. RUNTIME — M.init() executed for real in Node (jsdom-free — DataTable()
 *                is stubbed to capture what it was called with) across three
 *                configs: full page, Simple-POS (categories+project hidden),
 *                and a card-less host — asserts the sort column index is
 *                correct in every case and the resize handler is bound only
 *                when a card container exists.
 *   D. SERVER PARITY — api/account/get_expenses.php's own column-index map
 *                puts 'e.expense_date' at index 1 regardless of whether the
 *                Project column is present, matching the client's column 1
 *                exactly (so `order[0][column]=1` always sorts by the right
 *                SQL column).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function hasNode(): bool {
    $out = []; $rc = 0;
    exec('node --version 2>&1', $out, $rc);
    return $rc === 0;
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Static — valid JS syntax');
$jsFile = "$root/assets/js/tables/bms-expenses-table.js";
if (!file_exists($jsFile)) {
    fail('MISSING: assets/js/tables/bms-expenses-table.js');
} elseif (!hasNode()) {
    echo "  \033[33m⚠ node not on PATH — skipping JS syntax/runtime checks (sections 1 & 3)\033[0m\n";
} else {
    $out = []; $rc = 0;
    exec('node --check ' . escapeshellarg($jsFile) . ' 2>&1', $out, $rc);
    $rc === 0 ? pass('assets/js/tables/bms-expenses-table.js') : fail('node --check failed: ' . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$js = src($root, 'assets/js/tables/bms-expenses-table.js');
has($js, "var dateColIdx = visibleCols.findIndex(function (c) { return c.key === 'expense_date'; });", 'finds the date column by key, not a hardcoded index');
has($js, "order: (dateColIdx !== -1) ? [[dateColIdx, 'desc']] : [],", 'DataTable() is given an explicit newest-first order');
has($js, "\$(window).on('resize.' + cfg.tableId, function () { renderCards(cfg, dt); });", 'card/table view resyncs on window resize');
has($js, 'if (cfg.cardContainer) {', 'resize listener is gated on a card container actually existing');

$getExp = src($root, 'api/account/get_expenses.php');
has($getExp, "'e.expense_date', // 1: Date", "server's own column map keeps expense_date at a fixed index 1");

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — M.init() executed for real (Node)');
if (!hasNode()) {
    echo "  \033[33m⚠ skipped (no node)\033[0m\n";
} else {
    $nodeScript = <<<'JS'
const fs = require('fs');
const code = fs.readFileSync(process.argv[2], 'utf8');

let capturedOpts = null;
let resizeBound = null;

function makeJQueryLike(isWindow) {
  return {
    DataTable: function (opts) { capturedOpts = opts; return { api: function () { return this; } }; },
    on: function (evt) { if (isWindow) resizeBound = evt; }
  };
}
const fakeWindow = {};
function fakeDollar(sel) { return makeJQueryLike(sel === fakeWindow); }

const fn = new Function('window', '$', 'jQuery', code + '; return window.BMSExpensesTable;');
const BMSExpensesTable = fn(fakeWindow, fakeDollar, fakeDollar);

function run(cfg) {
  capturedOpts = null; resizeBound = null;
  BMSExpensesTable.init(Object.assign({
    apiUrl: '/api/get_expenses.php',
    perms: { canEdit: true, canDelete: true },
    i18n: { statusLabels: {} },
    urls: {}
  }, cfg));
  return { order: capturedOpts.order, colCount: capturedOpts.columns.length, resizeBound };
}

const results = {
  fullPage: run({ tableId: 'expensesTable', hide: [], cardContainer: '#mobile-expense-cards' }),
  simplePos: run({ tableId: 'expensesTable2', hide: ['categories', 'project'], cardContainer: '#mobile-expense-cards' }),
  noCard: run({ tableId: 'expensesTable3', hide: [], cardContainer: null }),
};
console.log(JSON.stringify(results));
JS;
    $tmpJs = sys_get_temp_dir() . '/bms_exp_table_test_' . uniqid() . '.js';
    file_put_contents($tmpJs, $nodeScript);
    $out = []; $rc = 0;
    exec('node ' . escapeshellarg($tmpJs) . ' ' . escapeshellarg($jsFile) . ' 2>&1', $out, $rc);
    @unlink($tmpJs);

    if ($rc !== 0) {
        fail('Node harness crashed: ' . implode(' ', $out));
    } else {
        $results = json_decode(end($out), true);
        if (!is_array($results)) {
            fail('Node harness produced unparseable output: ' . implode(' ', $out));
        } else {
            ($results['fullPage']['order'] ?? null) === [[1, 'desc']]
                ? pass('Full page (no hidden columns): order = [[1,"desc"]]')
                : fail('Full page order wrong: ' . json_encode($results['fullPage']['order'] ?? null));

            ($results['simplePos']['order'] ?? null) === [[1, 'desc']]
                ? pass('Simple POS (categories+project hidden): order still = [[1,"desc"]] (date column survives)')
                : fail('Simple POS order wrong: ' . json_encode($results['simplePos']['order'] ?? null));

            ($results['simplePos']['colCount'] ?? null) === 7
                ? pass('Simple POS: 7 visible columns (9 - categories - project)')
                : fail('Simple POS column count wrong: ' . json_encode($results['simplePos']['colCount'] ?? null));

            !empty($results['fullPage']['resizeBound'])
                ? pass('Full page: resize handler bound (has a card container)')
                : fail('Full page: expected a resize handler binding');

            empty($results['noCard']['resizeBound'])
                ? pass('No card container: no resize handler bound (nothing to resync)')
                : fail('No card container: unexpectedly bound a resize handler');
        }
    }
}
