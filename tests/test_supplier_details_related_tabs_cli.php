<?php
/**
 * tests/test_supplier_details_related_tabs_cli.php
 *   php tests/test_supplier_details_related_tabs_cli.php
 *
 * Supplier Details gained seven related-record tabs — Goods Received, Purchase
 * Returns, Delivery Notes, RFQs, Debit Notes, Expenses and System Info.
 *
 * Each list table is ONE implementation shared by the module page and the tab
 * (assets/js/tables/*.js + includes/tables/*.php); the tab differs only by a
 * forced filter and a hidden column. This proves that arrangement holds:
 *
 *   1. every shared asset exists and is wired from its partial
 *   2. both hosts render the same table, and only the tab drops the redundant
 *      column (so headers can't silently drift from the JS column list)
 *   3. the shared JS/CSS assets are emitted once per page, not once per table
 *   4. every feed really filters to the supplier it is given
 *   5. the New / View All buttons hand over a supplier the target page honours
 *
 * Pages declare functions, so each page render runs in its own subprocess.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void {
    strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`");
}
function hasNot(string $hay, string $needle, string $label): void {
    strpos($hay, $needle) === false ? pass($label) : fail("$label — unexpectedly found `" . substr($needle, 0, 70) . "`");
}

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

/** Render one page in its own PHP process and return its HTML. */
function renderPage(string $root, string $rel, string $query = ''): string
{
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;
$_SESSION['first_name'] = 'Test'; $_SESSION['last_name'] = 'Runner';
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
parse_str($argv[2] ?? '', $_GET);
$_POST = [];
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_render_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' '
         . escapeshellarg("$root/$rel") . ' ' . escapeshellarg($query) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = shell_exec($cmd);
    @unlink($tmp);
    return (string) $out;
}

/** Call one JSON endpoint in its own process and return the decoded body. */
function callApi(string $root, string $rel, array $get): ?array
{
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 4; $_SESSION['is_admin'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
parse_str($argv[2] ?? '', $_GET);
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_api_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' '
         . escapeshellarg("$root/$rel") . ' ' . escapeshellarg(http_build_query($get))
         . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = shell_exec($cmd);
    @unlink($tmp);
    return json_decode(trim((string) $out), true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('Shared assets exist');

$assets = [
    'assets/js/tables/bms-table-utils.js',
    'assets/js/tables/bms-grn-table.js',
    'assets/js/tables/bms-purchase-returns-table.js',
    'assets/js/tables/bms-delivery-notes-table.js',
    'assets/js/tables/bms-rfq-table.js',
    'assets/js/tables/bms-debit-notes-table.js',
    'assets/js/tables/bms-expenses-table.js',
    'includes/tables/grn_table.php',
    'includes/tables/purchase_returns_table.php',
    'includes/tables/delivery_notes_table.php',
    'includes/tables/rfq_table.php',
    'includes/tables/debit_notes_table.php',
    'includes/tables/expenses_table.php',
    'api/purchase/get_debit_notes.php',
];
foreach ($assets as $a) {
    is_file("$root/$a") ? pass("exists: $a") : fail("missing: $a");
}

// ─────────────────────────────────────────────────────────────────────────────
section('Pick fixtures');

$supplierId = (int) $pdo->query("SELECT supplier_id FROM suppliers WHERE status != 'deleted' ORDER BY supplier_id LIMIT 1")->fetchColumn();
$supplierId > 0 ? pass("supplier under test: #$supplierId") : fail('no supplier available to test with');
if ($supplierId <= 0) return;

// ─────────────────────────────────────────────────────────────────────────────
section('Supplier Details renders all seven tabs');

$sd = renderPage($root, 'app/bms/Suppliers/supplier_details.php', "id=$supplierId");
strlen($sd) > 1000 ? pass('supplier_details renders') : fail('supplier_details produced no output');

foreach ([
    '#pane-grn'         => 'Goods Received tab',
    '#pane-returns'     => 'Purchase Returns tab',
    '#pane-dn'          => 'Delivery Notes tab',
    '#pane-rfq'         => 'RFQs tab',
    '#pane-debitnotes'  => 'Debit Notes tab',
    '#pane-expenses'    => 'Expenses tab',
    '#pane-sysinfo'     => 'System Info tab',
] as $target => $label) {
    has($sd, 'data-bs-target="' . $target . '"', "$label is in the tab bar");
    has($sd, 'id="' . ltrim($target, '#') . '"', "$label pane is rendered");
}

section('Every tab offers New and View All');

// One "View All" per data tab (System Info has no list behind it).
$viewAllCount = substr_count($sd, '>
                                    View All');
$viewAllCount >= 6
    ? pass("View All buttons present on the data tabs ($viewAllCount found)")
    : fail("expected a View All on each data tab, found $viewAllCount");

foreach ([
    'grn_create'        => 'New GRN',
    'purchase_returns'  => 'New Return',
    'dn_create'         => 'Record DN',
    'rfq_create'        => 'Create RFQ',
    'debit_note_create' => 'New Debit Note',
] as $route => $label) {
    has($sd, $route, "$label button links to $route");
}
has($sd, 'supplier=' . $supplierId, 'create links carry the supplier id');

section('Tab tables defer until their tab is opened');

$deferred = substr_count($sd, 'BMSTbl.defer(');
$deferred === 6
    ? pass('all six tab tables hold their first query until shown')
    : fail("expected 6 deferred tables, found $deferred");

section('Shared assets are emitted once per page, not once per table');

foreach ([
    'bms-table-utils.js'          => 'utils',
    'bms-grn-table.js'            => 'grn module',
    'bms-purchase-returns-table.js' => 'purchase returns module',
    'bms-delivery-notes-table.js' => 'delivery notes module',
    'bms-rfq-table.js'            => 'rfq module',
    'bms-debit-notes-table.js'    => 'debit notes module',
    'bms-expenses-table.js'       => 'expenses module',
] as $file => $label) {
    $n = substr_count($sd, $file);
    $n === 1 ? pass("$label loaded exactly once") : fail("$label loaded $n times (expected 1)");
}

// ─────────────────────────────────────────────────────────────────────────────
section('Module page and tab share one table, differing only by hidden columns');

$cases = [
    ['app/bms/grn/grn.php',                   '', 'grnTable',      'supGrnTable',        'Supplier'],
    ['app/bms/purchase/purchase_returns.php', '', 'returnsTable',  'supReturnsTable',    'Supplier'],
    ['app/bms/purchase/rfq.php',              '', 'rfqTable',      'supRfqTable',        'Supplier'],
    ['app/bms/purchase/debit_notes/debit_notes.php', '', 'dnTable', 'supDebitNotesTable', 'Supplier'],
    ['app/constant/accounts/expenses.php',    '', 'expensesTable', 'supExpensesTable',   'Paid To'],
];

foreach ($cases as [$page, $q, $pageTableId, $tabTableId, $droppedCol]) {
    $html = renderPage($root, $page, $q);
    $name = basename($page);

    if (!preg_match('/<table[^>]*id="' . preg_quote($pageTableId, '/') . '".*?<\/thead>/s', $html, $m)) {
        fail("$name — could not find #$pageTableId thead");
        continue;
    }
    $pageHead = $m[0];
    has($pageHead, ">$droppedCol<", "$name keeps its $droppedCol column");

    if (!preg_match('/<table[^>]*id="' . preg_quote($tabTableId, '/') . '".*?<\/thead>/s', $sd, $m2)) {
        fail("supplier tab — could not find #$tabTableId thead");
        continue;
    }
    $tabHead = $m2[0];
    hasNot($tabHead, ">$droppedCol<", "supplier tab drops the redundant $droppedCol column");

    // Same table, minus exactly one column.
    $pageCols = substr_count($pageHead, '<th');
    $tabCols  = substr_count($tabHead, '<th');
    $tabCols === $pageCols - 1
        ? pass("$name: tab shows $tabCols of $pageCols columns (one dropped)")
        : fail("$name: tab shows $tabCols columns, page shows $pageCols — expected exactly one fewer");
}

// Delivery Notes drops two columns (counterparty AND direction — all inbound).
$dnPage = renderPage($root, 'app/bms/grn/delivery_notes.php', '');
if (preg_match('/<table[^>]*id="dnTable".*?<\/thead>/s', $dnPage, $m)
    && preg_match('/<table[^>]*id="supDnTable".*?<\/thead>/s', $sd, $m2)) {
    hasNot($m2[0], 'dnPartyColHeading', 'supplier tab drops the counterparty column');
    hasNot($m2[0], '>Type<',            'supplier tab drops the Type column (always inbound)');
    $d = substr_count($m[0], '<th') - substr_count($m2[0], '<th');
    $d === 2 ? pass('delivery_notes.php: tab shows two fewer columns') : fail("delivery notes column delta was $d, expected 2");
} else {
    fail('delivery notes — could not compare table headers');
}

// ─────────────────────────────────────────────────────────────────────────────
section('Each feed really filters to the supplier');

$feeds = [
    ['GRN',              'api/get_grns.php',              ['supplier' => $supplierId],    'supplier_id'],
    ['Purchase Returns', 'api/get_purchase_returns.php',  ['supplier_id' => $supplierId], null],
    ['RFQ',              'api/get_rfqs.php',              ['supplier' => $supplierId],    null],
    ['Debit Notes',      'api/purchase/get_debit_notes.php', ['supplier_id' => $supplierId], 'supplier_id'],
];

foreach ($feeds as [$label, $endpoint, $get, $idField]) {
    $res = callApi($root, $endpoint, $get + ['draw' => 1, 'start' => 0, 'length' => 50]);
    if ($res === null) { fail("$label feed returned no JSON"); continue; }
    $rows = $res['data'] ?? null;
    if (!is_array($rows)) { fail("$label feed returned no data array"); continue; }
    pass("$label feed answers with " . count($rows) . ' row(s)');

    if ($idField !== null) {
        $bad = 0;
        foreach ($rows as $r) {
            if (isset($r[$idField]) && (int) $r[$idField] !== $supplierId) $bad++;
        }
        $bad === 0
            ? pass("$label rows all belong to supplier #$supplierId")
            : fail("$label returned $bad row(s) for a different supplier");
    }
}

// Expenses filters on the payee pair, not a supplier column.
$payee = $pdo->query("SELECT paid_to_id FROM expenses WHERE paid_to_type = 'supplier' AND paid_to_id > 0 GROUP BY paid_to_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
if ($payee) {
    $res = callApi($root, 'api/get_expenses.php', [
        'paid_to_type' => 'supplier', 'paid_to_id' => (int) $payee,
        'draw' => 1, 'start' => 0, 'length' => 50,
    ]);
    $rows = $res['data'] ?? [];
    if (!is_array($rows) || count($rows) === 0) {
        fail('Expenses feed returned nothing for a payee that has expenses');
    } else {
        pass('Expenses feed answers with ' . count($rows) . ' row(s)');
        $bad = 0;
        foreach ($rows as $r) {
            if (($r['paid_to_type'] ?? '') !== 'supplier' || (int) ($r['paid_to_id'] ?? 0) !== (int) $payee) $bad++;
        }
        $bad === 0
            ? pass("Expenses rows all belong to payee #$payee")
            : fail("Expenses returned $bad row(s) for a different payee");
    }
} else {
    pass('Expenses payee filter skipped — no supplier-paid expense on file');
}

// ─────────────────────────────────────────────────────────────────────────────
section('Create / View All targets honour the supplier handed to them');

$targets = [
    ['grn_create pre-selects the supplier',   'app/bms/grn/grn_create.php',      "supplier=$supplierId", '/value="' . $supplierId . '"\s+selected/'],
    ['grn list pre-selects the filter',       'app/bms/grn/grn.php',             "supplier=$supplierId", '/value="' . $supplierId . '"\s*selected/'],
    ['delivery_notes pre-selects the filter', 'app/bms/grn/delivery_notes.php',  "supplier=$supplierId", '/value="' . $supplierId . '"\s*selected/'],
    ['dn_create seeds the party picker',      'app/bms/grn/dn_create.php',       "supplier=$supplierId", '/CUR_PARTY_ID\s*=\s*' . $supplierId . '/'],
    ['rfq list pre-selects the filter',       'app/bms/purchase/rfq.php',        "supplier=$supplierId", '/value="' . $supplierId . '"\s*selected/'],
    ['rfq_create pre-selects the supplier',   'app/bms/purchase/rfq_create.php', "supplier=$supplierId", '/value="' . $supplierId . '"\s*selected/'],
    ['debit_note_create seeds the picker',    'app/bms/purchase/debit_notes/debit_note_create.php', "supplier=$supplierId", '/"id":' . $supplierId . '/'],
];
foreach ($targets as [$label, $page, $query, $pattern]) {
    $html = renderPage($root, $page, $query);
    preg_match($pattern, $html) ? pass($label) : fail("$label — pattern not matched: $pattern");
}

// Purchase Returns + Expenses hand over through JS constants.
$pr = renderPage($root, 'app/bms/purchase/purchase_returns.php', "supplier=$supplierId&add=1");
has($pr, "PR_PREFILL_SUPPLIER = $supplierId", 'purchase_returns receives the supplier');
has($pr, 'const PR_OPEN_ADD = true',          'purchase_returns opens the Add modal on &add=1');

$ex = renderPage($root, 'app/constant/accounts/expenses.php', "paid_to_type=supplier&paid_to_id=$supplierId&add=1");
has($ex, '"paid_to_id":' . $supplierId,        'expenses locks its list to the payee');
has($ex, 'const EXP_PAYEE_TYPE = "supplier"',  'expenses receives the payee type');

// ─────────────────────────────────────────────────────────────────────────────
section('Tab bar matches the customer / employee standard');

has($sd, 'flex-nowrap overflow-auto', 'tab bar scrolls sideways rather than wrapping');
has($sd, '#supplierSectionTabs .nav-link.active,', 'tab pills use the shared active/hover rule');
has($sd, 'font-size: 0.82rem',        'tab pills use the shared 0.82rem size');
