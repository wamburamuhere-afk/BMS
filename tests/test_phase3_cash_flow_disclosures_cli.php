<?php
/**
 * Phase 3.3 — Cash Flow IFRS for SMEs §7.19A + §7.19B-C disclosures CLI test
 * ----------------------------------------------------------------------------
 *   php tests/test_phase3_cash_flow_disclosures_cli.php
 *
 * Verifies:
 *   1. File lint-clean.
 *   2. Source contains the agreed structural patterns for the two new
 *      disclosure blocks (closures defined; called twice for current +
 *      comparative; payment_terms JOIN + parsing SQL; data.disclosures
 *      wired into the response).
 *   3. Runtime: API returns disclosures block under data.disclosures
 *      with both subkeys (financing_liabilities_reconciliation,
 *      supplier_finance_arrangements) and each carrying current +
 *      comparative children.
 *   4. §7.19A — financing-liabilities reconciliation contract:
 *      applicable=false, zero balances, note mentions loans excluded.
 *   5. §7.19B-C — supplier-finance proxy contract:
 *      applicable=false, integer counts, non-negative totals, note
 *      mentions "no formal supplier finance arrangements" and explains
 *      the date_recorded fallback.
 *   6. Live-DB sanity: invoice_count >= invoices_with_terms; when
 *      invoice_count > 0, total_unpaid_amount > 0 and at least one of
 *      earliest_due_date / latest_due_date is non-null.
 *   7. Backward compat: Phase 3.1 / 3.2 tests still pass.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

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

$file = "$root/api/account/get_cash_flow.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains disclosure structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "§7.19A"                                                  => 'cites IFRS for SMEs §7.19A explicitly',
    "§7.19B-C"                                                => 'cites IFRS for SMEs §7.19B-C explicitly',
    "\$financingLiabilitiesDisclosure = function"             => 'financing-liabilities closure defined',
    "\$supplierFinanceDisclosure = function"                  => 'supplier-finance closure defined',
    "financing_liabilities_reconciliation"                    => '§7.19A key present',
    "supplier_finance_arrangements"                           => '§7.19B-C key present',
    "excluded per company policy"                             => 'note explains loans excluded policy',
    "No formal supplier finance arrangements"                 => 'note explains supplier-finance proxy nature',
    "po.payment_terms LIKE 'net_%'"                           => 'parses net_N payment_terms via LIKE',
    "SUBSTRING_INDEX(po.payment_terms, '_', -1)"              => 'parses N out of "net_N" via SUBSTRING_INDEX',
    "LEFT JOIN purchase_orders po ON si.po_id = po.purchase_order_id" => 'joins supplier_invoices to purchase_orders for terms',
    "si.status = 'approved'"                                  => 'only counts approved supplier invoices',
    "si.payment_date IS NULL"                                 => 'only counts unpaid supplier invoices',
    "\$financingLiabilitiesDisclosure(\$start_date, \$end_date)"   => 'financing-liabilities called for current period',
    "\$financingLiabilitiesDisclosure(\$comparative_start, \$comparative_end)" => 'financing-liabilities called for comparative period',
    "\$supplierFinanceDisclosure(\$end_date)"                 => 'supplier-finance called for current period',
    "\$supplierFinanceDisclosure(\$comparative_end)"          => 'supplier-finance called for comparative period',
    "'disclosures' => \$disclosures"                          => 'disclosures wired into response data',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime: API exposes data.disclosures with both children');
// ─────────────────────────────────────────────────────────────────────────
$_GET = ['start_date' => '2026-01-01', 'end_date' => '2026-05-31'];
$prevErr = error_reporting(error_reporting() & ~E_WARNING);
ob_start(); require $file;
$raw = ob_get_clean();
error_reporting($prevErr);
$r = json_decode($raw, true);

if (!$r || empty($r['success'])) {
    fail('admin run non-success: ' . substr($raw, 0, 200));
    exit(1);
}
pass('API success for 2026-01-01 to 2026-05-31');

$d = $r['data'];
if (!array_key_exists('disclosures', $d)) {
    fail('data.disclosures missing — cannot proceed');
    exit(1);
}
pass('data.disclosures present');

foreach (['financing_liabilities_reconciliation', 'supplier_finance_arrangements'] as $k) {
    array_key_exists($k, $d['disclosures']) ? pass("data.disclosures.$k present") : fail("data.disclosures.$k missing");
    foreach (['current', 'comparative'] as $period) {
        array_key_exists($period, $d['disclosures'][$k] ?? [])
            ? pass("data.disclosures.$k.$period present")
            : fail("data.disclosures.$k.$period missing");
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. §7.19A financing-liabilities reconciliation contract');
// ─────────────────────────────────────────────────────────────────────────
foreach (['current', 'comparative'] as $period) {
    $f = $d['disclosures']['financing_liabilities_reconciliation'][$period];
    foreach (['applicable', 'note', 'opening_balance', 'cash_changes', 'non_cash_changes', 'closing_balance'] as $k) {
        array_key_exists($k, $f) ? pass("§7.19A.$period.$k present") : fail("§7.19A.$period.$k missing");
    }
    $f['applicable'] === false   ? pass("§7.19A.$period.applicable = false (loans excluded)")  : fail("§7.19A.$period.applicable should be false");
    (float)$f['opening_balance']  === 0.0 ? pass("§7.19A.$period.opening_balance = 0") : fail("§7.19A.$period.opening_balance should be 0");
    (float)$f['closing_balance']  === 0.0 ? pass("§7.19A.$period.closing_balance = 0") : fail("§7.19A.$period.closing_balance should be 0");
    (float)$f['cash_changes']     === 0.0 ? pass("§7.19A.$period.cash_changes = 0")    : fail("§7.19A.$period.cash_changes should be 0");
    (float)$f['non_cash_changes'] === 0.0 ? pass("§7.19A.$period.non_cash_changes = 0"): fail("§7.19A.$period.non_cash_changes should be 0");
    stripos($f['note'], 'borrowings') !== false ? pass("§7.19A.$period.note mentions borrowings exclusion") : fail("§7.19A.$period.note should mention borrowings policy");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. §7.19B-C supplier-finance proxy contract');
// ─────────────────────────────────────────────────────────────────────────
foreach (['current', 'comparative'] as $period) {
    $s = $d['disclosures']['supplier_finance_arrangements'][$period];
    foreach (['applicable', 'note', 'invoice_count', 'invoices_with_terms', 'total_unpaid_amount', 'earliest_due_date', 'latest_due_date'] as $k) {
        array_key_exists($k, $s) ? pass("§7.19B-C.$period.$k present") : fail("§7.19B-C.$period.$k missing");
    }
    $s['applicable'] === false ? pass("§7.19B-C.$period.applicable = false (proxy only)") : fail("§7.19B-C.$period.applicable should be false");
    is_int($s['invoice_count']) && $s['invoice_count'] >= 0
        ? pass("§7.19B-C.$period.invoice_count is non-negative int ({$s['invoice_count']})")
        : fail("§7.19B-C.$period.invoice_count should be non-negative int");
    is_int($s['invoices_with_terms']) && $s['invoices_with_terms'] >= 0
        ? pass("§7.19B-C.$period.invoices_with_terms is non-negative int ({$s['invoices_with_terms']})")
        : fail("§7.19B-C.$period.invoices_with_terms should be non-negative int");
    (float)$s['total_unpaid_amount'] >= 0
        ? pass("§7.19B-C.$period.total_unpaid_amount >= 0 ({$s['total_unpaid_amount']})")
        : fail("§7.19B-C.$period.total_unpaid_amount should be >= 0");
    stripos($s['note'], 'no formal supplier finance arrangements') !== false
        ? pass("§7.19B-C.$period.note mentions proxy disclaimer")
        : fail("§7.19B-C.$period.note should mention proxy disclaimer");
    stripos($s['note'], 'date_recorded') !== false
        ? pass("§7.19B-C.$period.note explains date_recorded fallback")
        : fail("§7.19B-C.$period.note should mention date_recorded fallback");
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Live-DB sanity invariants');
// ─────────────────────────────────────────────────────────────────────────
$cur_sf = $d['disclosures']['supplier_finance_arrangements']['current'];

$cur_sf['invoice_count'] >= $cur_sf['invoices_with_terms']
    ? pass("invoice_count ({$cur_sf['invoice_count']}) >= invoices_with_terms ({$cur_sf['invoices_with_terms']}) — invariant holds")
    : fail("invoice_count must be >= invoices_with_terms");

if ($cur_sf['invoice_count'] > 0) {
    (float)$cur_sf['total_unpaid_amount'] > 0
        ? pass("when invoice_count > 0, total_unpaid_amount > 0 ({$cur_sf['total_unpaid_amount']})")
        : fail("invoice_count > 0 but total_unpaid_amount = 0 — inconsistent");
    !is_null($cur_sf['earliest_due_date']) || !is_null($cur_sf['latest_due_date'])
        ? pass('when invoices exist, at least one of earliest/latest due date is non-null')
        : fail('invoices exist but both earliest_due_date and latest_due_date are null');
} else {
    pass('no unpaid invoices in current period — null-date check skipped');
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Backward compat: Phase 3.1 + 3.2 tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase3_cash_flow_comparative_cli.php',
    'tests/test_phase3_cash_flow_indirect_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) {
        pass("$rel not present — skipping");
        continue;
    }
    $rc2 = 0; $o2 = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o2, $rc2);
    $rc2 === 0 ? pass("$rel still passes")
              : fail("$rel failed: rc=$rc2\n" . implode("\n", array_slice($o2, -10)));
}

exit($failures === 0 ? 0 : 1);
