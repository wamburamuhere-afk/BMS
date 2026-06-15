<?php
/**
 * PO summary "Approved" card + PO→Invoice remaining-aware conversion — CLI test
 *   php tests/test_po_invoicing_cli.php
 *
 * Covers two fixes:
 *   1. api/account/get_purchase_orders.php now returns stats.approved_amount /
 *      approved_count (the "Approved" summary card read an undefined field → showed 0).
 *   2. helpers.php::ri_po_billing() + api/account/po_to_supplier_invoice.php — a PO is
 *      billed incrementally. Convert bills only the REMAINING un-invoiced balance
 *      (scaling PO lines), and a fully-invoiced PO can't be converted again. The
 *      cumulative cap stays as the safety net.
 *
 * Real endpoint run with EXPLICIT TEARDOWN (the convert endpoint manages its own
 * transaction, so we delete every supplier_invoice / item / signature it creates and
 * restore the PO), leaving the database exactly as found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/helpers.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

// Track artefacts to remove on exit (set is filled as the test creates invoices).
$CREATED_INVOICE_IDS = [];
$SYNTH_INVOICE_IDS   = [];
function teardown_invoices(PDO $pdo, array $ids): void {
    foreach (array_unique($ids) as $iid) {
        $iid = (int)$iid; if ($iid <= 0) continue;
        $pdo->prepare("DELETE FROM supplier_invoice_items WHERE invoice_id = ?")->execute([$iid]);
        $pdo->prepare("DELETE FROM workflow_signatures WHERE entity_type = 'supplier_invoice' AND entity_id = ?")->execute([$iid]);
        $pdo->prepare("DELETE FROM supplier_invoices WHERE id = ?")->execute([$iid]);
    }
}

register_shutdown_function(function () use ($pdo, &$CREATED_INVOICE_IDS, &$SYNTH_INVOICE_IDS) {
    teardown_invoices($pdo, array_merge($CREATED_INVOICE_IDS, $SYNTH_INVOICE_IDS));
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$runEndpoint = function (array $post) use ($root) {
    $_POST = $post; $_SERVER['REQUEST_METHOD'] = 'POST';
    if (function_exists('csrf_token')) { $_POST['_csrf'] = csrf_token(); $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token(); }
    $prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE);
    ob_start(); require $root . '/api/account/po_to_supplier_invoice.php'; $raw = ob_get_clean();
    error_reporting($prev);
    return json_decode($raw, true);
};

// GET runner for api/received_invoices.php actions, which call exit() — so they must
// run in a SUBPROCESS or they'd terminate this harness.
$callRiGet = function (array $get) use ($root) {
    $tmp = tempnam(sys_get_temp_dir(), 'bmsri') . '.php';
    $code = "<?php\n"
          . "session_start();\n"
          . "\$_SESSION['user_id']=4; \$_SESSION['username']='admin'; \$_SESSION['role']='admin'; \$_SESSION['is_admin']=true;\n"
          . "\$_SERVER['REQUEST_METHOD']='GET';\n"
          . "\$_GET=" . var_export($get, true) . ";\n"
          . "error_reporting(E_ERROR|E_PARSE);\n"
          . "require " . var_export($root . '/api/received_invoices.php', true) . ";\n";
    file_put_contents($tmp, $code);
    $null = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
    $out  = shell_exec('php ' . escapeshellarg($tmp) . ' 2>' . $null);
    @unlink($tmp);
    return json_decode($out, true);
};

// ─────────────────────────────────────────────────────────────────────────
section('0. Approved summary card: stats.approved_amount is returned + correct');
$expectedApproved = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM purchase_orders WHERE status='approved'")->fetchColumn();
$_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE);
ob_start(); require $root . '/api/account/get_purchase_orders.php'; $raw = ob_get_clean();
error_reporting($prev);
$po = json_decode($raw, true);
if (!$po || empty($po['success'])) {
    fail('get_purchase_orders did not succeed: ' . substr($raw, 0, 160));
} else {
    array_key_exists('approved_amount', $po['stats']) ? pass('stats.approved_amount present') : fail('stats.approved_amount MISSING (card would show 0)');
    array_key_exists('approved_count', $po['stats'])  ? pass('stats.approved_count present')  : fail('stats.approved_count missing');
    (abs((float)($po['stats']['approved_amount'] ?? -1) - $expectedApproved) < 0.01)
        ? pass('approved_amount = ' . money($expectedApproved) . ' (matches Σ approved POs)')
        : fail('approved_amount ' . money((float)$po['stats']['approved_amount']) . ' != ' . money($expectedApproved));
}

// ─────────────────────────────────────────────────────────────────────────
section('1. ri_po_billing reflects live invoices (synthetic partial, committed, torn down)');
// A clean approved PO with items and nothing billed yet.
$clean = $pdo->query("
    SELECT po.purchase_order_id, po.order_number, po.grand_total, po.supplier_id
      FROM purchase_orders po
     WHERE po.status = 'approved'
       AND po.grand_total > 1000
       AND EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = po.purchase_order_id)
       AND COALESCE((SELECT SUM(amount) FROM supplier_invoices si WHERE si.po_id = po.purchase_order_id AND si.status<>'deleted'),0) = 0
  ORDER BY po.grand_total DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$clean) { fail('no clean approved PO with items and no invoices — cannot run'); return; }
$poId   = (int)$clean['purchase_order_id'];
$poNum  = $clean['order_number'];
$poTot  = round((float)$clean['grand_total'], 2);
echo "   using PO #$poId $poNum total=" . money($poTot) . "\n";

$b0 = ri_po_billing($pdo, $poId);
($b0['billing_status'] === 'not_billed' && abs($b0['remaining'] - $poTot) < 0.01)
    ? pass("not_billed: remaining = full PO (" . money($b0['remaining']) . ")")
    : fail("expected not_billed/full remaining, got " . json_encode($b0));

// Insert a synthetic partial bill = 30% of the PO.
$partAmt = round($poTot * 0.30, 2);
$pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, subtotal, tax_amount, status, recorded_by)
               VALUES ('supplier', ?, 'TST-PARTIAL', CURDATE(), CURDATE(), ?, ?, ?, 0, 'pending', ?)")
    ->execute([$clean['supplier_id'], $poId, $partAmt, $partAmt, $_SESSION['user_id']]);
$SYNTH_INVOICE_IDS[] = (int)$pdo->lastInsertId();

$b1 = ri_po_billing($pdo, $poId);
(abs($b1['billed'] - $partAmt) < 0.01) ? pass('billed reflects the partial (' . money($partAmt) . ')') : fail('billed wrong: ' . money($b1['billed']));
(abs($b1['remaining'] - round($poTot - $partAmt, 2)) < 0.01) ? pass('remaining = total − billed (' . money($b1['remaining']) . ')') : fail('remaining wrong: ' . money($b1['remaining']));
($b1['billing_status'] === 'partly_billed') ? pass("billing_status = partly_billed") : fail("status = {$b1['billing_status']}");

// ─────────────────────────────────────────────────────────────────────────
section('1b. Received Invoices page agrees with the shared helper (po_summary + get_po_items)');
// po_summary now derives from ri_po_billing — the manual-entry page and the PO page
// can never disagree. (Run in a subprocess; the action calls exit().)
$ps = $callRiGet(['action' => 'po_summary', 'po_id' => $poId]);
if ($ps && !empty($ps['success'])) {
    $d = $ps['data'];
    (abs((float)$d['invoiced_total'] - $partAmt) < 0.01) ? pass('po_summary.invoiced_total = ' . money($partAmt)) : fail('po_summary invoiced ' . money((float)$d['invoiced_total']));
    (abs((float)$d['remaining'] - $b1['remaining']) < 0.01) ? pass('po_summary.remaining matches ri_po_billing') : fail('po_summary remaining ' . money((float)$d['remaining']) . ' != ' . money($b1['remaining']));
    (($d['billing_status'] ?? '') === 'partly_billed') ? pass("po_summary.billing_status = partly_billed") : fail('po_summary status = ' . ($d['billing_status'] ?? '?'));
} else {
    fail('po_summary subprocess failed: ' . json_encode($ps));
}

// get_po_items?scale_remaining=1 → quantities scaled so the rebuilt total = remaining.
$gi = $callRiGet(['action' => 'get_po_items', 'po_id' => $poId, 'scale_remaining' => 1]);
if ($gi && !empty($gi['success'])) {
    $amt = 0.0;
    foreach ($gi['data'] as $it) {
        $ls   = (float)$it['quantity'] * (float)$it['unit_price'];
        $amt += $ls + $ls * ((float)($it['tax_rate'] ?? 0) / 100);
    }
    (abs($amt - $b1['remaining']) < 1.0)
        ? pass('get_po_items scaled to the remaining (rebuilt total ' . money($amt) . ')')
        : fail('get_po_items scaled total ' . money($amt) . ' != remaining ' . money($b1['remaining']));
} else {
    fail('get_po_items subprocess failed: ' . json_encode($gi));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Convert bills ONLY the remaining balance (partial)');
$expectRemaining = $b1['remaining'];
$res = $runEndpoint(['po_id' => $poId]);
if (!$res || empty($res['success'])) {
    fail('convert (partial) failed: ' . json_encode($res));
} else {
    $CREATED_INVOICE_IDS[] = (int)$res['invoice_id'];
    pass('convert succeeded: ' . ($res['message'] ?? ''));
    (!empty($res['is_partial'])) ? pass('flagged is_partial') : fail('should be flagged partial');
    (abs((float)$res['amount'] - $expectRemaining) < 0.5)
        ? pass('invoice amount = remaining (' . money((float)$res['amount']) . ')')
        : fail('invoice amount ' . money((float)$res['amount']) . ' != remaining ' . money($expectRemaining));
    // Stored invoice total matches.
    $storedAmt = (float)$pdo->query("SELECT amount FROM supplier_invoices WHERE id=" . (int)$res['invoice_id'])->fetchColumn();
    (abs($storedAmt - $expectRemaining) < 0.5) ? pass('stored supplier_invoice.amount = remaining') : fail('stored amount wrong: ' . money($storedAmt));
    // Items were scaled (sum of line_total ≈ remaining).
    $itemSum = (float)$pdo->query("SELECT COALESCE(SUM(line_total),0) FROM supplier_invoice_items WHERE invoice_id=" . (int)$res['invoice_id'])->fetchColumn();
    (abs($itemSum - $expectRemaining) < 1.0) ? pass('scaled line items sum to the remaining (' . money($itemSum) . ')') : fail('item sum ' . money($itemSum) . ' != remaining');
}

// ─────────────────────────────────────────────────────────────────────────
section('3. PO is now fully invoiced → convert would be blocked');
// The endpoint guards on exactly these two conditions before creating anything:
//   (a) ri_po_billing()['remaining'] <= 0.001  → "already fully invoiced" early-exit
//   (b) ri_check_po_cap(...) rejects any further amount (safety net)
// We assert both directly (the endpoint calls exit() on the block path, which would
// terminate this harness, so we prove the guards rather than trip the exit).
$b2 = ri_po_billing($pdo, $poId);
($b2['billing_status'] === 'fully_billed' && $b2['remaining'] <= 0.01)
    ? pass('(a) billing_status = fully_billed, remaining ≈ 0 → convert early-exits')
    : fail('expected fully_billed, got ' . json_encode($b2));
$cap = ri_check_po_cap($pdo, $poId, 1.0, null);   // any extra on a fully-billed PO must fail
(!$cap['ok']) ? pass('(b) ri_check_po_cap rejects billing beyond a fully-invoiced PO') : fail('cap wrongly allowed an over-invoice');

// ─────────────────────────────────────────────────────────────────────────
section('4. Teardown leaves the PO exactly as found');
teardown_invoices($pdo, array_merge($CREATED_INVOICE_IDS, $SYNTH_INVOICE_IDS));
$CREATED_INVOICE_IDS = []; $SYNTH_INVOICE_IDS = [];
$b3 = ri_po_billing($pdo, $poId);
($b3['billing_status'] === 'not_billed' && abs($b3['remaining'] - $poTot) < 0.01)
    ? pass('PO back to not_billed, remaining = full total (clean teardown)')
    : fail('PO not restored: ' . json_encode($b3));
