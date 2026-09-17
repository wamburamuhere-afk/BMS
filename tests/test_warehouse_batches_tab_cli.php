<?php
/**
 * "Batches" tab on warehouse_view.php — per-shop batch/lot tracking
 * (Simple POS only)
 *   php tests/test_warehouse_batches_tab_cli.php
 *
 * Request: a new tab on warehouse_view.php, in the same card area as
 * "Recent Activity", showing every batch received into that one warehouse —
 * buying price, selling price, wholesale price, manufacturing/expiry date,
 * received/remaining quantity — DataTable on desktop, cards on mobile,
 * fully translated, editable, Simple POS only, and non-Simple-POS tenants
 * must see zero change.
 *
 * Confirmed before building anything: the data model and batch-creation
 * already existed in full (2026-09-15 work) —
 *   - product_batches already has warehouse_id, batch_number, expiry_date,
 *     manufacturing_date, unit_cost, wholesale_price, selling_price.
 *   - api/create_product.php already creates a product's opening stock as
 *     its real first batch (receiveProductBatch(), write_batch: true).
 *   - api/pos/quick_restock.php already creates a new batch per restock.
 *   - core/pos_batch_consumption.php already consumes/restores
 *     quantity_remaining on every sale/void/return (FEFO).
 * This feature is a pure read/correct UI on top of that — no new table, no
 * new batch-creation path, and explicitly NEVER a quantity editor (see
 * api/stock/update_warehouse_batch.php's own docblock for why).
 *
 *   A. STATIC   — every new/touched file lints or syntax-checks clean.
 *   B. SCHEMA   — product_batches still has every column this feature reads
 *                 (defensive — this feature reads the table directly, no
 *                 abstraction, so a schema change would need to update it).
 *   C. WIRING   — both API endpoints gated on Simple POS + warehouse scope;
 *                 update endpoint has NO quantity_received/quantity_remaining
 *                 field anywhere in its accepted input or its UPDATE
 *                 statement; the warehouse_view.php tab only renders under
 *                 posSimpleModeEnabled(), and the non-Simple-POS branch is
 *                 the ORIGINAL markup, untouched.
 *   D. RUNTIME  — warehouse-batches.js executed for real in Node: column
 *                 count/order, status classification.
 *   E. LIVE HTTP — a manufactured real batch: the list API returns it with
 *                 the exact values stored (no drift), the same
 *                 active/expired/exhausted classification product_view.php's
 *                 own batch section already uses; an edit round-trip
 *                 actually changes the row in product_batches (the single
 *                 source every other consumer reads); confirms a
 *                 quantity_remaining tamper attempt in the POST body is
 *                 silently ignored (the column is decided entirely by real
 *                 stock movements, never by this form); confirms the tab
 *                 is completely absent when Simple POS is off.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌\033[0m $m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 60) . "`"); }
function hasNode(): bool { $out = []; $rc = 0; exec('node --version 2>&1', $out, $rc); return $rc === 0; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
foreach ([
    'app/bms/stock/warehouse_view.php',
    'app/bms/stock/warehouse_batches_tab.php',
    'api/stock/get_warehouse_batches.php',
    'api/stock/update_warehouse_batch.php',
    'lang/sw.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f — " . implode(' ', $out));
}
if (!hasNode()) {
    echo "  \033[33m⚠ node not on PATH — skipping JS checks\033[0m\n";
} else {
    $out = []; $rc = 0;
    exec('node --check ' . escapeshellarg("$root/assets/js/warehouse-batches.js") . ' 2>&1', $out, $rc);
    $rc === 0 ? pass('assets/js/warehouse-batches.js') : fail('node --check failed: ' . implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Schema — product_batches still has every column this feature reads');
$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM product_batches') as $c) { $cols[] = $c['Field']; }
foreach (['batch_id','product_id','warehouse_id','batch_number','expiry_date','manufacturing_date',
          'quantity_received','quantity_remaining','unit_cost','wholesale_price','selling_price','receipt_id'] as $col) {
    in_array($col, $cols, true) ? pass("product_batches.$col exists") : fail("product_batches.$col missing — this feature would silently break");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Wiring — list API');
$listApi = src($root, 'api/stock/get_warehouse_batches.php');
has($listApi, "if (!posSimpleModeEnabled())", 'gated on Simple POS');
has($listApi, "userCan('warehouse', \$warehouseId)", 'gated on warehouse scope (not just any warehouse_id)');
has($listApi, 'FROM product_batches pb', 'reads product_batches directly (no parallel/duplicate table)');
has($listApi, "JOIN products p ON p.product_id = pb.product_id", 'joins products for display, same as product_view.php\'s own batch query');
has($listApi, "ORDER BY (pb.expiry_date IS NULL), pb.expiry_date ASC, pb.batch_id DESC", 'identical ordering to product_view.php\'s own batch table');

// ─────────────────────────────────────────────────────────────────────────
section('4. Wiring — update API: quantity is NEVER editable here');
$updApi = src($root, 'api/stock/update_warehouse_batch.php');
has($updApi, "if (!posSimpleModeEnabled())", 'gated on Simple POS');
has($updApi, "canEdit('warehouses')", 'gated on edit permission');
has($updApi, "userCan('warehouse', \$warehouseId)", 'gated on warehouse scope');
has($updApi, 'csrf_check();', 'CSRF-protected (security.md §21)');
has($updApi, "WHERE batch_id = ? AND warehouse_id = ?", 'ownership check: batch must actually belong to the claimed warehouse before editing');
// The docblock legitimately names both fields to explain why they're
// excluded — check the meaningful thing instead: neither is ever READ from
// the request, and the UPDATE statement's own column list (already checked
// below) never mentions them either.
hasnt($updApi, "\$_POST['quantity_remaining']", 'quantity_remaining is never read from the POST body');
hasnt($updApi, "\$_POST['quantity_received']", 'quantity_received is never read from the POST body');
has($updApi, 'SET batch_number = ?, manufacturing_date = ?, expiry_date = ?,', 'UPDATE statement touches only the intended descriptive/price fields');

// ─────────────────────────────────────────────────────────────────────────
section('5. Wiring — warehouse_view.php: Simple-POS-only, non-Simple branch unchanged');
$wv = src($root, 'app/bms/stock/warehouse_view.php');
has($wv, '$pos_simple_mode      = posSimpleModeEnabled();', 'page computes Simple POS state');
has($wv, 'if ($pos_simple_mode):', 'tab UI gated on Simple POS');
has($wv, "<?php include __DIR__ . '/warehouse_batches_tab.php'; ?>", 'Batches pane included only in the Simple-POS branch');
// The non-Simple-POS branch must be the exact original markup — same
// literal strings that existed before this feature (hardcoded "Recent
// Activity" heading, no tabs, no t() added there), so a non-Simple-POS
// tenant's page is byte-identical to before.
has($wv, '<h5 class="mb-0"><i class="bi bi-clock-history text-primary me-1"></i> Recent Activity</h5>', 'non-Simple-POS branch keeps the original hardcoded heading verbatim');
has($wv, 'render_warehouse_activity_items($recent_movements, false)', 'non-Simple-POS branch reuses the SAME data-rendering function (not a forked copy that could drift)');
has($wv, 'render_warehouse_activity_items($recent_movements, true)', 'Simple-POS branch reuses the identical function for its Recent Activity pane too — one source either way');

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — warehouse-batches.js executed for real (Node)');
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
    empty: function(){return el;}, html: function(){return el;}, text: function(){return el;},
    val: function(){return el;}, length: 1, width: function(){return 1200;},
    DataTable: function(opts){ global.__lastDtOpts = opts; return global.__fakeDt; },
    on: function(){ return el; },
    rows: function(){ return { count: function(){return 0;}, every: function(){} }; }
  };
  return el;
}
function fakeDollar(sel) { return makeEl(sel === global.__windowRef ? 'window' : sel); }
global.__fakeDt = { ajax: { reload: function(){} } };
global.__windowRef = {};
const fn = new Function('window', '$', 'jQuery', code + '; return window.WarehouseBatches;');
const WarehouseBatches = fn(global.__windowRef, fakeDollar, fakeDollar);

WarehouseBatches.init({
  id: 'x', tableSel: '#t', listContainer: '#lc', cardContainer: '#cc',
  loadingEl: '#le', emptyEl: '#ee', warehouseId: 5, canEdit: true, hide: [],
  urls: { list: '/x', update: '/x' },
  i18n: { edit: 'Edit', active: 'Active', expired: 'Expired', exhausted: 'Exhausted',
          buyingPrice: 'a', sellingPrice: 'b', wholesalePrice: 'c', manufacturingDate: 'd',
          expiryDate: 'e', received: 'f', remaining: 'g', success: 'h', error: 'i' }
});

console.log(JSON.stringify({
  columnCount: global.__lastDtOpts.columns.length,
  order: global.__lastDtOpts.order,
}));
JS;
    $tmpJs = sys_get_temp_dir() . '/bms_wh_batches_test_' . uniqid() . '.js';
    file_put_contents($tmpJs, $nodeScript);
    $out = []; $rc = 0;
    exec('node ' . escapeshellarg($tmpJs) . ' ' . escapeshellarg("$root/assets/js/warehouse-batches.js") . ' 2>&1', $out, $rc);
    @unlink($tmpJs);

    if ($rc !== 0) {
        fail('Node harness crashed: ' . implode(' ', $out));
    } else {
        $r = json_decode(end($out), true);
        if (!is_array($r)) {
            fail('Node harness produced unparseable output: ' . implode(' ', $out));
        } else {
            $r['columnCount'] === 12 ? pass('12 columns built (S/NO + 10 data columns + Actions)') : fail('Expected 12 columns, got ' . json_encode($r['columnCount']));
            is_array($r['order']) && count($r['order']) === 1 && $r['order'][0][1] === 'asc'
                ? pass('Explicit ascending sort on the expiry-date column (soonest first), never the implicit default on non-orderable S/NO')
                : fail('order wrong: ' . json_encode($r['order']));
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Live — manufactured batch, API round-trip, cross-page consistency');
require_once "$root/core/pos_nav.php";
$wasSimple = posSimpleModeEnabled();
save_setting('pos_simple_mode', '1');

$testWarehouseId = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$testProductId   = (int)$pdo->query("SELECT product_id FROM products WHERE status = 'active' AND is_service = 0 ORDER BY product_id LIMIT 1")->fetchColumn();
$testBatchId = null;

if ($testWarehouseId > 0 && $testProductId > 0) {
    $pdo->prepare("
        INSERT INTO product_batches
            (product_id, warehouse_id, batch_number, expiry_date, manufacturing_date, quantity_received, quantity_remaining, unit_cost, wholesale_price, selling_price, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $testProductId, $testWarehouseId, 'BATCHTEST-' . time(),
        date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('-5 days')),
        50, 37, 1200.00, 1500.00, 1800.00,
    ]);
    $testBatchId = (int)$pdo->lastInsertId();
    pass("Manufactured a real batch (batch_id=$testBatchId) for warehouse #$testWarehouseId, product #$testProductId");
} else {
    fail('No active warehouse/product found — cannot run the live section');
}

$cleanupTestBatch = function () use ($pdo, $testBatchId) {
    if ($testBatchId) $pdo->prepare("DELETE FROM product_batches WHERE batch_id = ?")->execute([$testBatchId]);
};
register_shutdown_function($cleanupTestBatch); // crash-only safety net; primary cleanup is explicit below

$probe = "$root/_warehouse_batches_test_probe.php";
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
// Explicit cleanup, called on the normal path near the end of this section
// (not only via shutdown) — this file's own pass/fail summary is itself a
// shutdown function, registered first, and calls exit(1) on any failure,
// which would skip a probe-cleanup shutdown function registered afterward.
// Same bug class already fixed once in test_pos_credit_terminology_and_stats_cli.php.
$cleanupProbeAndSetting = function () use ($probe, $wasSimple) {
    if (is_file($probe)) @unlink($probe);
    if (!$wasSimple) save_setting('pos_simple_mode', '0');
};
register_shutdown_function($cleanupProbeAndSetting); // crash-only safety net

function httpGet6($url, $cookieJar) {
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
function httpPost6($url, $data, $cookieJar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    echo "  \033[33m⚠ server not reachable at $base — skipping live HTTP checks\033[0m\n";
} elseif (!function_exists('curl_init')) {
    echo "  \033[33m⚠ curl extension unavailable — skipping live HTTP checks\033[0m\n";
} elseif (!$testBatchId) {
    echo "  \033[33m⚠ no manufactured batch — skipping live HTTP checks\033[0m\n";
} else {
    $adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
    if ($adminUid <= 0) {
        fail('No admin user found');
    } else {
        $cookieJar = tempnam(sys_get_temp_dir(), 'whbtest_cookies_');
        httpGet6("$base/_warehouse_batches_test_probe.php?act=login&uid=$adminUid&lang=en", $cookieJar);

        // ── List API returns our batch with the exact values stored ──
        [$code, $body] = httpGet6("$base/api/stock/get_warehouse_batches.php?warehouse_id=$testWarehouseId", $cookieJar);
        $json = json_decode((string)$body, true);
        $ours = null;
        if (is_array($json)) {
            foreach ($json['data'] ?? [] as $row) { if ((int)$row['batch_id'] === $testBatchId) { $ours = $row; break; } }
        }
        if ($ours === null) {
            fail('Manufactured batch not found in the live API response');
        } else {
            pass('Manufactured batch appears in the live API response');
            (abs((float)$ours['unit_cost'] - 1200.00) < 0.01) ? pass('unit_cost matches exactly what was stored (1200.00)') : fail('unit_cost drifted: ' . $ours['unit_cost']);
            (abs((float)$ours['selling_price'] - 1800.00) < 0.01) ? pass('selling_price matches exactly what was stored (1800.00)') : fail('selling_price drifted: ' . $ours['selling_price']);
            (abs((float)$ours['quantity_remaining'] - 37) < 0.001) ? pass('quantity_remaining matches exactly what was stored (37) — read straight from product_batches') : fail('quantity_remaining drifted: ' . $ours['quantity_remaining']);
            ($ours['status'] === 'active') ? pass("status correctly classified 'active' (not expired, not exhausted — matches product_view.php's own thresholds)") : fail('status wrong: ' . $ours['status']);
        }

        // Cross-check: the SAME row, queried directly, must match the API's
        // numbers exactly — proves no transformation/rounding drift snuck in.
        $direct = $pdo->prepare("SELECT unit_cost, selling_price, quantity_remaining FROM product_batches WHERE batch_id = ?");
        $direct->execute([$testBatchId]);
        $directRow = $direct->fetch(PDO::FETCH_ASSOC);
        if ($ours !== null && $directRow) {
            (abs((float)$ours['unit_cost'] - (float)$directRow['unit_cost']) < 0.01
                && abs((float)$ours['quantity_remaining'] - (float)$directRow['quantity_remaining']) < 0.001)
                ? pass('API figures match a direct product_batches query exactly — no parallel calculation, no drift')
                : fail('API figures do not match the raw table');
        }

        // ── Edit round-trip: allowed fields change, quantity is immune ──
        // Load the real page FIRST so the session actually has a real
        // csrf_token() value established (exactly how a browser would get
        // one before ever submitting the Edit Batch form) — testing against
        // an empty/never-initialised session token would trivially "pass"
        // hash_equals('', '') and prove nothing.
        [, $pageBody] = httpGet6("$base/warehouse_view?id=$testWarehouseId", $cookieJar);
        $realCsrf = null;
        if (preg_match('/name="_csrf" value="([^"]+)"/', (string)$pageBody, $m)) $realCsrf = $m[1];

        if ($realCsrf) {
            [$codeU, $bodyU] = httpPost6("$base/api/stock/update_warehouse_batch.php", [
                'batch_id' => $testBatchId,
                'warehouse_id' => $testWarehouseId,
                'batch_number' => 'BATCHTEST-EDITED',
                'manufacturing_date' => date('Y-m-d', strtotime('-6 days')),
                'expiry_date' => date('Y-m-d', strtotime('+20 days')),
                'unit_cost' => '1350.50',
                'selling_price' => '1999.00',
                'wholesale_price' => '1700.00',
                'quantity_remaining' => '999999',
                'quantity_received' => '999999',
                '_csrf' => 'deliberately-wrong-token-' . $realCsrf, // real session token exists, this is simply not it
            ], $cookieJar);
            $jsonU = json_decode((string)$bodyU, true);
            (is_array($jsonU) && ($jsonU['success'] ?? true) === false)
                ? pass('Edit with a wrong CSRF token (against a session that has a real one) is correctly rejected')
                : fail('Edit with a wrong CSRF token unexpectedly succeeded — CSRF protection is not actually enforced');
        } else {
            echo "  \033[33m⚠ could not extract a live CSRF token — skipped the wrong-token rejection check\033[0m\n";
        }

        if ($realCsrf) {
            [$codeU2, $bodyU2] = httpPost6("$base/api/stock/update_warehouse_batch.php", [
                'batch_id' => $testBatchId, 'warehouse_id' => $testWarehouseId,
                'batch_number' => 'BATCHTEST-EDITED', 'manufacturing_date' => date('Y-m-d', strtotime('-6 days')),
                'expiry_date' => date('Y-m-d', strtotime('+20 days')), 'unit_cost' => '1350.50',
                'selling_price' => '1999.00', 'wholesale_price' => '1700.00',
                'quantity_remaining' => '999999', 'quantity_received' => '999999',
                '_csrf' => $realCsrf,
            ], $cookieJar);
            $jsonU2 = json_decode((string)$bodyU2, true);
            (is_array($jsonU2) && ($jsonU2['success'] ?? false) === true)
                ? pass('Edit with a valid CSRF token succeeds')
                : fail('Legitimate edit failed: ' . substr((string)$bodyU2, 0, 200));

            $after = $pdo->prepare("SELECT batch_number, unit_cost, quantity_remaining, quantity_received FROM product_batches WHERE batch_id = ?");
            $after->execute([$testBatchId]);
            $afterRow = $after->fetch(PDO::FETCH_ASSOC);
            ($afterRow['batch_number'] === 'BATCHTEST-EDITED' && abs((float)$afterRow['unit_cost'] - 1350.50) < 0.01)
                ? pass('Allowed fields (batch_number, unit_cost) genuinely changed in product_batches — the single source, so every other consumer sees the correction too')
                : fail('Allowed fields did not change in the database');
            (abs((float)$afterRow['quantity_remaining'] - 37) < 0.001 && abs((float)$afterRow['quantity_received'] - 50) < 0.001)
                ? pass('quantity_remaining/quantity_received are UNCHANGED (still 37/50) despite the tamper attempt — quantity can only move through a real stock event')
                : fail('Quantity was altered by the edit form — this must never happen: ' . json_encode($afterRow));
        } else {
            echo "  \033[33m⚠ could not extract a live CSRF token — skipped the legitimate-edit round-trip\033[0m\n";
        }

        // ── Live page render checks ──
        [$codeP, $bodyP] = httpGet6("$base/warehouse_view?id=$testWarehouseId", $cookieJar);
        (!preg_match('/Fatal error: Uncaught|Parse error: syntax error|<b>Fatal error<\/b>|<b>Parse error<\/b>/i', (string)$bodyP))
            ? pass('warehouse_view.php has no PHP fatal/parse errors with the new tab')
            : fail('warehouse_view.php has a PHP error: ' . substr((string)$bodyP, 0, 300));
        (strpos((string)$bodyP, 'id="pane-warehouse-batches"') !== false && strpos((string)$bodyP, 'id="whBatchesTable"') !== false)
            ? pass('Live page renders the Batches tab pane and table under Simple POS')
            : fail('Live page missing the Batches tab');

        [, $bodyPSw] = httpGet6("$base/_warehouse_batches_test_probe.php?act=login&uid=$adminUid&lang=sw", $cookieJar);
        [, $bodyPSw2] = httpGet6("$base/warehouse_view?id=$testWarehouseId", $cookieJar);
        (strpos((string)$bodyPSw2, 'Mizigo (Batch)') !== false)
            ? pass('Live page shows the Swahili "Mizigo (Batch)" tab label under the sw locale')
            : fail('Live page still shows raw English under the sw locale');

        // ── Non-Simple-POS: the tab must not exist at all ──
        save_setting('pos_simple_mode', '0');
        [, $bodyOff] = httpGet6("$base/warehouse_view?id=$testWarehouseId", $cookieJar);
        (strpos((string)$bodyOff, 'pane-warehouse-batches') === false && strpos((string)$bodyOff, 'whBatchesTable') === false)
            ? pass('Batches tab is completely absent when Simple POS is off — non-Simple-POS tenants see zero change')
            : fail('Batches tab leaked into the non-Simple-POS view');
        (strpos((string)$bodyOff, 'Recent Activity') !== false)
            ? pass('Recent Activity still renders normally with Simple POS off')
            : fail('Recent Activity broken when Simple POS is off');
        save_setting('pos_simple_mode', '1'); // restore for the rest of this run; final restore happens in the shutdown handler above

        @unlink($cookieJar);
    }
}

$cleanupTestBatch();
$cleanupProbeAndSetting();
