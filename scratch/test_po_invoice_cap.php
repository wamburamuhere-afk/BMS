<?php
/**
 * Test: Received Invoice — PO cumulative cap + PO vs Invoice report (update 34)
 * Static analysis + live DB functional tests.
 *
 * Run: http://localhost/bms/scratch/test_po_invoice_cap.php  (must be logged in)
 *  OR: php scratch/test_po_invoice_cap.php
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

$pass = 0; $fail = 0; $results = [];

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $results;
    if ($cond) { $pass++; $results[] = ['pass', $label, $detail]; }
    else        { $fail++; $results[] = ['fail', $label, $detail ?: 'condition was false']; }
}
function syntax(string $path): bool {
    $c = file_get_contents($path);
    if ($c === false) return false;
    try { token_get_all($c, TOKEN_PARSE); return true; } catch (ParseError $e) { return false; }
}

$root = dirname(__DIR__);

// ═══════════════════════════════════════════════════════════════════
// §1  PHP SYNTAX — every file touched in update 34
// ═══════════════════════════════════════════════════════════════════
$files = [
    'app/bms/invoice/received_invoices.php',
    'api/received_invoices.php',
    'app/bms/invoice/po_invoice_report.php',
    'api/po_invoice_report.php',
    'helpers.php',
    'roots.php',
    'header.php',
];
foreach ($files as $f) {
    syntax($root . '/' . $f) ? ok("Syntax OK: $f", true) : ok("Syntax OK: $f", false);
}

// ═══════════════════════════════════════════════════════════════════
// §2  FORM REORGANIZATION — PO above Amount + summary panel
// ═══════════════════════════════════════════════════════════════════
$riSrc = file_get_contents($root . '/app/bms/invoice/received_invoices.php');

// Field ORDER: PO must come BEFORE Amount in the form HTML
$poPos     = strpos($riSrc, 'id="supplier-fields"');
$amountPos = strpos($riSrc, 'id="f-amount"');
ok('PO Reference field appears before Amount field in form',
   $poPos !== false && $amountPos !== false && $poPos < $amountPos,
   "po=$poPos, amount=$amountPos");

ok('PO Summary panel container exists',         str_contains($riSrc, 'id="po-summary-wrap"'));
ok('PO Summary: PO Total cell',                  str_contains($riSrc, 'id="po-sum-total"'));
ok('PO Summary: Previously Invoiced cell',       str_contains($riSrc, 'id="po-sum-invoiced"'));
ok('PO Summary: Remaining cell',                 str_contains($riSrc, 'id="po-sum-remaining"'));
ok('PO Summary: After This Invoice cell',        str_contains($riSrc, 'id="po-sum-after"'));
ok('PO Summary: status badge',                   str_contains($riSrc, 'id="po-summary-status"'));
ok('PO Summary: warning area',                   str_contains($riSrc, 'id="po-sum-warning"'));
ok('Amount feedback element',                    str_contains($riSrc, 'id="f-amount-feedback"'));

// JS functions
ok('JS: loadPoSummary() defined',                str_contains($riSrc, 'function loadPoSummary('));
ok('JS: recalcPoAfter() defined',                str_contains($riSrc, 'function recalcPoAfter('));
ok('JS: hidePoSummary() defined',                str_contains($riSrc, 'function hidePoSummary('));
ok('JS: PO change handler wired',                str_contains($riSrc, "$('#f-po').on('change'"));
ok('JS: amount input handler wired',             str_contains($riSrc, "$('#f-amount').on('input'"));
ok('JS: client-side cap guard before submit',    str_contains($riSrc, 'Exceeds PO Amount'));
ok('JS: modal close clears summary',             str_contains($riSrc, 'hidePoSummary();'));
// sub-contractor mode should clear PO summary — find the SC branch and check for hidePoSummary inside it
$scBranch = '';
if (preg_match('/} else \{[\s\S]*?#sc-basis-wrap[\s\S]*?\}/', $riSrc, $m)) { $scBranch = $m[0]; }
ok('JS: sub-contractor mode clears summary',     str_contains($scBranch, 'hidePoSummary()'));

// ═══════════════════════════════════════════════════════════════════
// §3  SERVER-SIDE CAP VALIDATION
// ═══════════════════════════════════════════════════════════════════
$apiSrc     = file_get_contents($root . '/api/received_invoices.php');
$helpersSrc = file_get_contents($root . '/helpers.php');

ok('Helpers: ri_check_po_cap() helper defined',          str_contains($helpersSrc, 'function ri_check_po_cap('));
ok('Helpers: cap helper does SUM(amount) lookup',        str_contains($helpersSrc, 'SUM(amount)'));
ok('Helpers: cap helper excludes deleted invoices',      str_contains($helpersSrc, "status != 'deleted'"));
ok('Helpers: cap helper supports exclude_id for edits',  str_contains($helpersSrc, 'exclude_id'));
ok('Helpers: rejection message mentions returning supplier', str_contains($helpersSrc, 'Return the invoice to the supplier'));

// create action calls helper
$createIdx = strpos($apiSrc, "action === 'create'");
$createEnd = strpos($apiSrc, "action === 'update'", $createIdx);
$createBlk = substr($apiSrc, $createIdx, $createEnd - $createIdx);
ok('API create: calls ri_check_po_cap when po_id set', str_contains($createBlk, 'ri_check_po_cap($pdo, $po_id'));

// update action calls helper with exclude_id
$updateIdx = strpos($apiSrc, "action === 'update'");
$updateEnd = strpos($apiSrc, "action === 'change_status'", $updateIdx);
$updateBlk = substr($apiSrc, $updateIdx, $updateEnd - $updateIdx);
ok('API update: calls ri_check_po_cap with current ID', str_contains($updateBlk, 'ri_check_po_cap($pdo, $po_id, $amount, $id)'));

// ═══════════════════════════════════════════════════════════════════
// §4  PO SUMMARY ENDPOINT
// ═══════════════════════════════════════════════════════════════════
ok('API: po_summary action exists',                   str_contains($apiSrc, "action === 'po_summary'"));
ok('API po_summary: requires po_id',                  str_contains($apiSrc, 'po_id required'));
ok('API po_summary: returns grand_total',             str_contains($apiSrc, "'grand_total'"));
ok('API po_summary: returns invoiced_total',          str_contains($apiSrc, "'invoiced_total'"));
ok('API po_summary: returns remaining',               str_contains($apiSrc, "'remaining'"));
ok('API po_summary: returns invoice_count',           str_contains($apiSrc, "'invoice_count'"));

// ═══════════════════════════════════════════════════════════════════
// §5  REPORT PAGE
// ═══════════════════════════════════════════════════════════════════
$rptSrc = file_get_contents($root . '/app/bms/invoice/po_invoice_report.php');
$rptApi = file_get_contents($root . '/api/po_invoice_report.php');

ok('Report page: uses received_invoices permission',  str_contains($rptSrc, "autoEnforcePermission('received_invoices')"));
ok('Report page: stat card — Total POs',              str_contains($rptSrc, 'id="stat-pos"'));
ok('Report page: stat card — Fully Billed',           str_contains($rptSrc, 'id="stat-fully"'));
ok('Report page: stat card — Partially Billed',       str_contains($rptSrc, 'id="stat-partial"'));
ok('Report page: stat card — Over-billed',            str_contains($rptSrc, 'id="stat-over"'));
ok('Report page: filter — supplier',                  str_contains($rptSrc, 'id="f-supplier"'));
ok('Report page: filter — status',                    str_contains($rptSrc, 'id="f-status"'));
ok('Report page: filter — date range',                str_contains($rptSrc, 'id="f-from"') && str_contains($rptSrc, 'id="f-to"'));
ok('Report page: DataTable wrapper',                  str_contains($rptSrc, 'id="reportTable"'));
ok('Report page: mobile cards',                       str_contains($rptSrc, 'id="cardView"'));
ok('Report page: Excel export function',              str_contains($rptSrc, 'function exportExcel('));
ok('Report page: progress bar visual',                str_contains($rptSrc, 'progress-bar'));

ok('Report API: permission check',                    str_contains($rptApi, "canView('received_invoices')"));
ok('Report API: joins suppliers',                     str_contains($rptApi, 'LEFT JOIN suppliers'));
ok('Report API: aggregates invoiced totals',          str_contains($rptApi, 'SUM(amount) AS invoiced_total'));
ok('Report API: computes remaining',                  str_contains($rptApi, 'AS remaining'));
ok('Report API: excludes cancelled POs',              str_contains($rptApi, "NOT IN ('cancelled')"));
ok('Report API: excludes deleted invoices',           str_contains($rptApi, "status != 'deleted'"));

// ═══════════════════════════════════════════════════════════════════
// §6  ROUTES + MENU
// ═══════════════════════════════════════════════════════════════════
$routes = file_get_contents($root . '/roots.php');
ok('Route: po_invoice_report registered',             str_contains($routes, "'po_invoice_report' =>"));

$header = file_get_contents($root . '/header.php');
ok('Menu link: PO vs Invoice Report visible',         str_contains($header, "getUrl('po_invoice_report')"));

// ═══════════════════════════════════════════════════════════════════
// §7  LIVE DB FUNCTIONAL TESTS — cap rules with actual data
// ═══════════════════════════════════════════════════════════════════
$supplier_id = $pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();

if (!$supplier_id) {
    ok('Live tests skipped — no active supplier in DB', true, 'skip');
} else {
    // Create a test PO with grand_total = 200,000,000
    $po_number = 'PO-CAP-TEST-' . time();
    try {
        $pdo->prepare("INSERT INTO purchase_orders
            (order_number, supplier_id, order_date, status, grand_total, created_by)
            VALUES (?, ?, CURDATE(), 'approved', 200000000, 1)")
            ->execute([$po_number, $supplier_id]);
        $po_id = (int)$pdo->lastInsertId();
        ok('Live: test PO created with grand_total=200M', $po_id > 0, "po_id=$po_id");

        // First invoice: 50M (should pass)
        $cap1 = ri_check_po_cap($pdo, $po_id, 50000000, null);
        ok('Cap check #1 (50M < 200M): allows',           $cap1['ok'] === true);

        // Insert it (simulate accepted invoice)
        $pdo->prepare("INSERT INTO supplier_invoices
            (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, status, recorded_by)
            VALUES ('supplier', ?, ?, CURDATE(), CURDATE(), ?, 50000000, 'draft', 1)")
            ->execute([$supplier_id, $po_number . '-INV-01', $po_id]);
        $inv1 = (int)$pdo->lastInsertId();

        // Second invoice: 100M (50+100=150 < 200, should pass)
        $cap2 = ri_check_po_cap($pdo, $po_id, 100000000, null);
        ok('Cap check #2 (50M+100M=150M < 200M): allows', $cap2['ok'] === true,
           "invoiced=" . $cap2['invoiced'] . " after=" . $cap2['after']);
        $pdo->prepare("INSERT INTO supplier_invoices
            (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, status, recorded_by)
            VALUES ('supplier', ?, ?, CURDATE(), CURDATE(), ?, 100000000, 'draft', 1)")
            ->execute([$supplier_id, $po_number . '-INV-02', $po_id]);
        $inv2 = (int)$pdo->lastInsertId();

        // Third invoice: 52M (50+100+52=202 > 200, MUST REJECT — boss's example)
        $cap3 = ri_check_po_cap($pdo, $po_id, 52000000, null);
        ok('Cap check #3 (150M+52M=202M > 200M): REJECTS', $cap3['ok'] === false,
           "after=" . $cap3['after'] . ", message=" . substr($cap3['message'], 0, 90));
        ok('Cap rejection message mentions exceed amount', str_contains($cap3['message'], '2,000,000'));
        ok('Cap rejection message tells to return to supplier', str_contains($cap3['message'], 'Return'));

        // Exact equality edge case: 50M (150+50=200 == 200, should ALLOW)
        $capExact = ri_check_po_cap($pdo, $po_id, 50000000, null);
        ok('Cap check exact match (200M == 200M): allows', $capExact['ok'] === true);

        // Edit case: re-checking invoice #1 with 50M should pass (excludes itself)
        $capEdit = ri_check_po_cap($pdo, $po_id, 50000000, $inv1);
        ok('Cap check on edit (excludes current invoice): allows', $capEdit['ok'] === true);

        // Edit case: changing invoice #1 to 200M while #2 (100M) is also linked → reject
        $capEditOver = ri_check_po_cap($pdo, $po_id, 200000000, $inv1);
        ok('Cap check on edit overflow: rejects', $capEditOver['ok'] === false);

        // Report query smoke test
        $report = $pdo->prepare("
            SELECT po.grand_total, COALESCE(SUM(si.amount),0) AS invoiced
            FROM purchase_orders po
            LEFT JOIN supplier_invoices si ON si.po_id = po.purchase_order_id AND si.status != 'deleted'
            WHERE po.purchase_order_id = ?
        ");
        $report->execute([$po_id]);
        $rep = $report->fetch(PDO::FETCH_ASSOC);
        ok('Report aggregation matches inserts (150M invoiced)', (float)$rep['invoiced'] === 150000000.0,
           'invoiced=' . $rep['invoiced']);

        // Cleanup
        $pdo->prepare("DELETE FROM supplier_invoices WHERE po_id = ?")->execute([$po_id]);
        $pdo->prepare("DELETE FROM purchase_orders WHERE purchase_order_id = ?")->execute([$po_id]);
        ok('Live: test data cleaned up', true);

    } catch (PDOException $e) {
        ok('Live test failed with DB error', false, $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════
// §8  CHANGELOG
// ═══════════════════════════════════════════════════════════════════
$changelog = file_get_contents($root . '/changelog.md');
ok('changelog.md: update 35 entry present', str_contains($changelog, 'update 35'));

// ═══════════════════════════════════════════════════════════════════
$total = $pass + $fail;
$color = $fail === 0 ? '#198754' : '#dc3545';
?><!DOCTYPE html><html><head><meta charset="utf-8"><title>PO Cap Test</title>
<style>
body{font-family:system-ui,sans-serif;padding:24px;background:#f8f9fa;}
.summary{font-size:1.1rem;font-weight:600;padding:10px 18px;border-radius:6px;margin-bottom:20px;
  background:<?= $color ?>;color:#fff;display:inline-block;}
table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.1);}
th{background:#343a40;color:#fff;padding:10px 14px;text-align:left;font-size:.82rem;text-transform:uppercase;}
td{padding:8px 14px;border-bottom:1px solid #e9ecef;font-size:.88rem;vertical-align:top;}
.pass td:first-child{color:#198754;font-weight:700;} .fail td:first-child{color:#dc3545;font-weight:700;}
.fail td{background:#fff5f5;} .detail{color:#6c757d;font-size:.80rem;}
.section td{background:#e9ecef;font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.5px;color:#495057;}
</style></head><body>
<h2>PO vs Invoice Cap — Test Results (Update 34)</h2>
<div class="summary"><?= $pass ?> / <?= $total ?> passed<?= $fail ? " · <strong>$fail FAILED</strong>" : ' — all good!' ?></div>
<table><thead><tr><th style="width:60px;">Result</th><th>Test</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($results as [$s,$l,$d]): ?>
<tr class="<?= $s ?>"><td><?= strtoupper($s) ?></td><td><?= htmlspecialchars($l) ?></td><td class="detail"><?= htmlspecialchars($d) ?></td></tr>
<?php endforeach; ?>
</tbody></table></body></html>
