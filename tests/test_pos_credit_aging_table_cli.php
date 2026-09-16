<?php
/**
 * POS credit-receivables ("Who Owes Me" / "Madeni") — DataTable + mobile
 * card rewrite, and its translation coverage — CLI regression suite
 *   php tests/test_pos_credit_aging_table_cli.php
 *
 * User report: the "Who Owes Me" page (and its "Madeni" tab twin on the
 * customer detail page) — (1) the POS hub's "Who Owes Me" tile and several
 * strings on the page itself weren't translated into Swahili at all; (2) the
 * table wasn't a real DataTable on desktop (no sort/search/pagination); (3)
 * mobile had no card view, just the same table forced to scroll sideways.
 *
 * assets/js/pos-credit-aging.js was hand-built HTML-row insertion off one
 * non-paginated $.getJSON; rewritten to a real client-side DataTable (data
 * fetched once, DataTables handles sort/search/paging client-side — the API
 * was never paginated to begin with) plus a mobile card view, matching the
 * convention in bms-expenses-table.js (explicit order to dodge the
 * non-orderable-first-column footgun, drawCallback + resize-synced
 * card/table toggle). Both hosts (pos_credit_customers.php, unfiltered; and
 * customer_details.php's "Madeni" tab, filtered to one customer) share the
 * one rewritten module, so they can never drift apart again.
 *
 *   A. STATIC   — touched files lint/syntax clean.
 *   B. WIRING   — both PHP hosts pass the new tableSel/cardContainer keys;
 *                the Madeni tab (a non-default Bootstrap tab-pane) passes
 *                deferPane so its DataTable isn't built at zero width; both
 *                theads gained a matching S/NO column.
 *   C. RUNTIME  — pos-credit-aging.js executed for real in Node: column
 *                count/order, ajax.data's customer_id behaviour, dataSrc's
 *                onStats computation, deferPane gating (BMSTbl.defer stub),
 *                and resize-listener gating on cardContainer.
 *   D. TRANSLATION — every string the rewrite touches (page text + the
 *                previously-hardcoded-English JS payment-history table
 *                header, + posNavGroups()'s "Who Owes Me" hub tile) resolves
 *                to a real Swahili string, not a raw English fallback.
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

function hasNode(): bool { $out = []; $rc = 0; exec('node --version 2>&1', $out, $rc); return $rc === 0; }

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
foreach ([
    'app/bms/pos/pos_credit_customers.php',
    'app/bms/customer/customer_details.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}
if (!hasNode()) {
    echo "  \033[33m⚠ node not on PATH — skipping JS syntax/runtime checks\033[0m\n";
} else {
    $out = []; $rc = 0;
    exec('node --check ' . escapeshellarg("$root/assets/js/pos-credit-aging.js") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass('assets/js/pos-credit-aging.js') : fail('node --check failed: ' . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring — both PHP hosts');
$whoOwes = src($root, 'app/bms/pos/pos_credit_customers.php');
has($whoOwes, "tableSel: '#creditAgingTable',", 'Who Owes Me passes tableSel');
has($whoOwes, "cardContainer: '#creditAgingCards',", 'Who Owes Me passes cardContainer');
has($whoOwes, 'id="creditAgingCards"', 'Who Owes Me has the mobile card container div');
has($whoOwes, "<th style=\"width:50px;\"><?= t('S/NO') ?></th>", 'Who Owes Me thead gained the S/NO column');
has($whoOwes, "amountHeader: <?= json_encode(t('Amount')) ?>,", "Who Owes Me supplies amountHeader (was hardcoded 'Amount' in JS)");

$madeni = src($root, 'app/bms/customer/customer_details.php');
has($madeni, "tableSel: '#madeniAgingTable',", 'Madeni tab passes tableSel');
has($madeni, "cardContainer: '#madeniAgingCards',", 'Madeni tab passes cardContainer');
has($madeni, "deferPane: '#pane-madeni',", 'Madeni tab passes deferPane (non-default Bootstrap tab)');
has($madeni, 'id="madeniAgingTable"', 'Madeni table gained an id (it had none before — DataTable() needs a selector)');
has($madeni, 'id="madeniAgingCards"', 'Madeni tab has the mobile card container div');
has($madeni, "overdueBy: <?= json_encode(t('Overdue by %d day(s)')) ?>,", 'Madeni i18n now routes through t() (was a hardcoded English literal)');
has($madeni, "<?= t('Record Repayment') ?>", 'Madeni Repay modal heading now routes through t()');
has($madeni, "assets/js/tables/bms-table-utils.js", 'Madeni tab loads bms-table-utils.js (BMSTbl.defer dependency)');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — pos-credit-aging.js executed for real (Node)');
if (!hasNode()) {
    echo "  \033[33m⚠ skipped (no node)\033[0m\n";
} else {
    $nodeScript = <<<'JS'
global.document = {};
const fs = require('fs');
const code = fs.readFileSync(process.argv[2], 'utf8');

function makeEl(sel) {
  var el = {
    _sel: sel, addClass: function(){return el;}, removeClass: function(){return el;},
    empty: function(){return el;}, html: function(){return el;}, length: 1,
    width: function(){return 1200;},
    DataTable: function(opts){ global.__lastDtOpts = opts; return global.__fakeDt; },
    on: function(evt){ global.__resizeBound = global.__resizeBound || {}; global.__resizeBound[sel] = evt; return el; },
    rows: function(){ return { count: function(){return 0;}, every: function(){} }; }
  };
  return el;
}
function fakeDollar(sel) { return makeEl(sel === global.__windowRef ? 'window' : sel); }
global.__fakeDt = { ajax: { reload: function(){} } };
global.__windowRef = {};

const fn = new Function('window', '$', 'jQuery', code + '; return window.PosCreditAging;');
const PosCreditAging = fn(global.__windowRef, fakeDollar, fakeDollar);

function baseCfg(overrides) {
  return Object.assign({
    id: 'x', tableSel: '#t', listContainer: '#lc', cardContainer: '#cc',
    loadingEl: '#le', emptyEl: '#ee', customerId: null, canEdit: true, canDelete: true,
    urls: { list: '/x', detail: '/x', repay: '/x', edit: '/x', void: '/x' },
    i18n: {
      overdueBy: 'a %d', dueInDays: 'b %d', dueToday: 'c', noDueDate: 'd', partial: 'e', unpaid: 'f',
      paidInFull: 'g', confirmVoidTitle: 'h', confirmVoidText: 'i', voidReasonPlaceholder: 'j', yesVoid: 'k',
      cancel: 'l', success: 'm', error: 'n', view: 'o', repay: 'p', edit: 'q', delete: 'r', paymentHistory: 's',
      noPaymentsYet: 't', balanceDue: 'u', saleAmount: 'v', saleDate: 'Sale Date:', dueDate: 'Due Date:',
      amountHeader: 'Amount', methodHeader: 'Method', byHeader: 'By'
    }
  }, overrides || {});
}

const results = {};

global.__lastDtOpts = null; global.__resizeBound = {};
PosCreditAging.init(baseCfg({ id: 'who-owes-me' }));
results.columnCount = global.__lastDtOpts.columns.length;
results.order = global.__lastDtOpts.order;
results.resizeBoundImmediate = !!global.__resizeBound.window;
results.ajaxDataNoCustomer = global.__lastDtOpts.ajax.data({});

var statsSeen = null;
var cfgStats = baseCfg({ id: 'stats-test' });
cfgStats.onStats = function (s) { statsSeen = s; };
global.__lastDtOpts = null;
PosCreditAging.init(cfgStats);
var json = { success: true, data: [{ is_overdue: true }, { is_overdue: false }, { is_overdue: true }], total_outstanding: 999, count: 3 };
var out = global.__lastDtOpts.ajax.dataSrc(json);
results.dataSrcReturnedCount = out.length;
results.onStats = statsSeen;

global.__lastDtOpts = null;
PosCreditAging.init(baseCfg({ id: 'madeni', customerId: 42 }));
results.ajaxDataWithCustomer = global.__lastDtOpts.ajax.data({});

global.__lastDtOpts = null;
var deferredFn = null;
global.__windowRef.BMSTbl = { defer: function (sel, fn2) { deferredFn = fn2; } };
PosCreditAging.init(baseCfg({ id: 'deferred', deferPane: '#pane-madeni' }));
results.dtCalledBeforeShown = global.__lastDtOpts !== null;
deferredFn();
results.dtCalledAfterShown = global.__lastDtOpts !== null;
delete global.__windowRef.BMSTbl;

global.__lastDtOpts = null; global.__resizeBound = {};
PosCreditAging.init(baseCfg({ id: 'no-card', cardContainer: null }));
results.resizeBoundWithoutCard = !!global.__resizeBound.window;

console.log(JSON.stringify(results));
JS;
    $tmpJs = sys_get_temp_dir() . '/bms_credit_aging_test_' . uniqid() . '.js';
    file_put_contents($tmpJs, $nodeScript);
    $out = []; $rc = 0;
    exec('node ' . escapeshellarg($tmpJs) . ' ' . escapeshellarg("$root/assets/js/pos-credit-aging.js") . ' 2>&1', $out, $rc);
    @unlink($tmpJs);

    if ($rc !== 0) {
        fail('Node harness crashed: ' . implode(' ', $out));
    } else {
        $r = json_decode(end($out), true);
        if (!is_array($r)) {
            fail('Node harness produced unparseable output: ' . implode(' ', $out));
        } else {
            $r['columnCount'] === 8 ? pass('8 columns built (S/NO + 7 data columns)') : fail('Expected 8 columns, got ' . json_encode($r['columnCount']));
            $r['order'] === [] ? pass('order: [] — no implicit sort on the non-orderable S/NO column') : fail('order wrong: ' . json_encode($r['order']));
            $r['resizeBoundImmediate'] ? pass('resize handler bound when cardContainer is set') : fail('expected a resize handler binding');
            $r['ajaxDataNoCustomer'] === [] ? pass('ajax.data omits customer_id when cfg.customerId is null') : fail('ajax.data should be empty: ' . json_encode($r['ajaxDataNoCustomer']));
            ($r['ajaxDataWithCustomer']['customer_id'] ?? null) === 42 ? pass('ajax.data includes customer_id=42 for the Madeni host') : fail('ajax.data missing customer_id: ' . json_encode($r['ajaxDataWithCustomer']));
            $r['dataSrcReturnedCount'] === 3 ? pass('dataSrc() returns the full row array to DataTables') : fail('dataSrc returned wrong count: ' . json_encode($r['dataSrcReturnedCount']));
            (($r['onStats']['overdueCount'] ?? null) === 2 && ($r['onStats']['totalOutstanding'] ?? null) === 999)
                ? pass('onStats() computed correctly from the fetched JSON (overdueCount=2, totalOutstanding=999)')
                : fail('onStats wrong: ' . json_encode($r['onStats']));
            (!$r['dtCalledBeforeShown'] && $r['dtCalledAfterShown'])
                ? pass('deferPane holds DataTable() until the tab is actually shown')
                : fail('deferPane gating wrong: before=' . json_encode($r['dtCalledBeforeShown']) . ' after=' . json_encode($r['dtCalledAfterShown']));
            (!$r['resizeBoundWithoutCard'])
                ? pass('no resize handler bound when cardContainer is null')
                : fail('unexpectedly bound a resize handler with no card container');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Translation coverage');
require_once "$root/core/i18n.php";
loadLanguage('sw');
$keys = [
    'Who Owes Me', 'Total Owed', 'Owed', 'Open Credit Sales', 'Nobody owes you anything right now',
    'Sale Date', 'Sale Date:', 'Due Date:', 'No due date', 'Due today', 'Due in %d day(s)',
    'Overdue by %d day(s)', 'Paid in full', 'Credit Sale Details', 'Edit Due Date', 'Record Repayment',
    'Repay', 'Balance Due:', 'Sale Amount:', 'Payment History', 'No payments recorded yet.',
    'Void this credit sale?', 'This reverses the stock and cash. Cannot be undone.', 'Yes, void it',
    'View Who Owes Me', 'Track customers who bought on credit — due dates, repayments, overdue.',
    'Customers with an open credit sale — due dates, repayments, overdue.',
    // Previously hardcoded plain-English literals in the JS payment-history
    // table header — now routed through t() and already covered elsewhere
    // in the app's Swahili catalog.
    'Amount', 'Method', 'By',
];
$missing = [];
foreach ($keys as $k) {
    if (t($k) === $k) $missing[] = $k;
}
empty($missing)
    ? pass('All ' . count($keys) . ' strings touched by this fix have a real Swahili translation')
    : fail('Missing Swahili translation for: ' . implode(' | ', $missing));

// ─────────────────────────────────────────────────────────────────────────
section('5. Live HTTP render check (both hosts, English + Swahili)');
global $pdo;
$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    echo "  \033[33m⚠ server not reachable at $base — skipping live render checks\033[0m\n";
} elseif (!function_exists('curl_init')) {
    echo "  \033[33m⚠ curl extension unavailable — skipping live render checks\033[0m\n";
} else {
    require_once "$root/core/pos_nav.php";
    $wasSimple = posSimpleModeEnabled();
    save_setting('pos_simple_mode', '1');

    $adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    $anyCustomerId = (int)$pdo->query("SELECT customer_id FROM customers ORDER BY customer_id LIMIT 1")->fetchColumn();

    // Same self-login probe pattern as tests/test_pos_credit_receivables_cli.php
    // — a temp CLI-only endpoint that sets the session directly (never
    // touches or resets any real password), deleted again in the shutdown
    // handler below regardless of how this section exits.
    $probe = "$root/_credit_aging_table_test_probe.php";
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    if (!empty($_GET['lang'])) { $_SESSION['user_lang'] = $_GET['lang']; }
    loadUserPermissions(1);
    echo 'ok';
}
PHP);
    register_shutdown_function(function () use ($probe, $wasSimple) {
        if (is_file($probe)) @unlink($probe);
        if (!$wasSimple) save_setting('pos_simple_mode', '0');
    });

    function httpGet2($url, $cookieJar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    }

    if ($adminUid <= 0) {
        fail('No admin user found — cannot run live render checks');
    } else {
        // ── English locale ──
        $cookieJar = tempnam(sys_get_temp_dir(), 'catest_cookies_');
        httpGet2("$base/_credit_aging_table_test_probe.php?act=login&uid=$adminUid&lang=en", $cookieJar);

        [$code, $body] = httpGet2("$base/pos/credit-customers", $cookieJar);
        (!preg_match('/Fatal error|Parse error/i', (string)$body))
            ? pass('Who Owes Me page has no PHP fatal/parse errors after the rewrite')
            : fail('Who Owes Me page has a PHP error: ' . substr((string)$body, 0, 300));
        (str_contains((string)$body, 'id="creditAgingTable"') && str_contains((string)$body, 'id="creditAgingCards"'))
            ? pass('Who Owes Me page renders both the DataTable and the mobile-card container')
            : fail('Who Owes Me page missing expected DOM ids');

        if ($anyCustomerId > 0) {
            [$codeC, $bodyC] = httpGet2("$base/customers/view?id=$anyCustomerId", $cookieJar);
            (!preg_match('/Fatal error|Parse error/i', (string)$bodyC))
                ? pass('customer_details.php has no PHP fatal/parse errors after the rewrite')
                : fail('customer_details.php has a PHP error: ' . substr((string)$bodyC, 0, 300));
            (str_contains((string)$bodyC, 'id="madeniAgingTable"') && str_contains((string)$bodyC, 'id="madeniAgingCards"'))
                ? pass('Madeni tab renders both the DataTable and the mobile-card container')
                : fail('Madeni tab missing expected DOM ids');
        } else {
            echo "  \033[33m⚠ no customers in DB — skipping Madeni tab live check\033[0m\n";
        }

        // ── Swahili locale — proves the translations actually reach the page ──
        $cookieJarSw = tempnam(sys_get_temp_dir(), 'catest_cookies_sw_');
        httpGet2("$base/_credit_aging_table_test_probe.php?act=login&uid=$adminUid&lang=sw", $cookieJarSw);
        [$codeSw, $bodySw] = httpGet2("$base/pos/credit-customers", $cookieJarSw);
        str_contains((string)$bodySw, 'Wanaonidai')
            ? pass('Who Owes Me page shows real Swahili text ("Wanaonidai") under the sw locale')
            : fail('Who Owes Me page still shows raw English under the sw locale');

        [$codeHub, $bodyHub] = httpGet2("$base/pos_dashboard", $cookieJarSw);
        str_contains((string)$bodyHub, 'Wanaonidai')
            ? pass('POS hub tile shows the Swahili "Who Owes Me" label under the sw locale')
            : fail('POS hub tile still shows raw English under the sw locale');

        @unlink($cookieJar);
        @unlink($cookieJarSw);
    }
}
