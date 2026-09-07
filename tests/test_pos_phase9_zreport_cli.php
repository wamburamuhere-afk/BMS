<?php
/**
 * POS Phase 9 — Z-Report / EOD reconciliation — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase9_zreport_cli.php
 *
 * Verifies:
 *   1. New/touched files lint-clean.
 *   2. Wiring source patterns:
 *      - roots.php registers pos/zreport and pos/shifts routes
 *      - zreport.php is permission-gated (canView('pos')) and restricts a
 *        non-supervisor to their own shift, calls posShiftTenderTotals() +
 *        posShiftReportExtras()
 *      - shift_history.php restricts a non-supervisor to their own shifts
 *      - feature_registry.php's 'pos' feature paths cover the new files
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation):
 *      posShiftReportExtras() correctly counts a voided sale, a return, and an
 *      un-posted-to-GL completed sale — while NOT flagging a completed sale
 *      that DOES have a posted 'pos_sale' journal entry.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/pos_shift_reporting.php";
require_once "$root/core/sales_posting.php";

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

$files = ['app/bms/pos/zreport.php', 'app/bms/pos/shift_history.php', 'core/pos_shift_reporting.php', 'roots.php', 'core/feature_registry.php'];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$rootsSrc   = file_get_contents("$root/roots.php");
$zreportSrc = file_get_contents("$root/app/bms/pos/zreport.php");
$histSrc    = file_get_contents("$root/app/bms/pos/shift_history.php");
$regSrc     = file_get_contents("$root/core/feature_registry.php");

$checks = [
    [$rootsSrc,   "'pos/zreport'",                                 "roots.php registers the pos/zreport route"],
    [$rootsSrc,   "'pos/shifts'",                                  "roots.php registers the pos/shifts route"],
    [$zreportSrc, "canView('pos')",                                'zreport.php gated by canView(pos)'],
    [$zreportSrc, "!canEdit('pos')",                                'zreport.php restricts a non-supervisor to their own shift'],
    [$zreportSrc, "posShiftTenderTotals(\$pdo, \$shift_id)",        'zreport.php calls posShiftTenderTotals()'],
    [$zreportSrc, "posShiftReportExtras(\$pdo, \$shift_id)",        'zreport.php calls posShiftReportExtras()'],
    [$histSrc,    "canEdit('pos')",                                'shift_history.php gates visibility by canEdit(pos)'],
    [$histSrc,    "sh.user_id = :uid",                             'shift_history.php restricts a non-supervisor to their own shifts'],
    [$regSrc,     "'app/bms/pos/zreport.php'",                     "feature_registry.php 'pos' paths include zreport.php"],
    [$regSrc,     "'app/bms/pos/shift_history.php'",               "feature_registry.php 'pos' paths include shift_history.php"],
    [$regSrc,     "'core/pos_shift_reporting.php'",                "feature_registry.php 'pos' paths include pos_shift_reporting.php"],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. posShiftReportExtras() — live-DB (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$product = $pdo->query("SELECT product_id, cost_price, selling_price FROM products
                          WHERE is_service = 0 AND cost_price > 0 AND selling_price >= cost_price
                          ORDER BY product_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$product) { fail('no suitable product found for the GL-posted sale leg'); exit(1); }

$synth_shift_id = 90000404;
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_id, shift_code, user_id, register_id, starting_cash, status, created_at)
                    VALUES (?, 'PHASE9-TEST-SHIFT', 4, 1, 0, 'active', NOW())")
        ->execute([$synth_shift_id]);

    $ins = $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, subtotal, tax_amount, discount_amount, grand_total, payment_method, sale_status, payment_status, is_return_sale, sale_date, created_at)
                           VALUES (?, ?, 4, ?, 0, 0, ?, ?, ?, 'paid', ?, NOW(), NOW())");
    // A: a voided sale
    $ins->execute(['PHASE9-A', $synth_shift_id, 4000, 4000, 'cash', 'voided', 0]);
    $voidedSaleId = (int)$pdo->lastInsertId();
    // B: a return
    $ins->execute(['PHASE9-B', $synth_shift_id, 1200, 1200, 'cash', 'refunded', 1]);
    // C: a completed sale with NO GL posting (simulates a misconfigured chart of accounts)
    $ins->execute(['PHASE9-C', $synth_shift_id, 6000, 6000, 'cash', 'completed', 0]);
    $unpostedSaleId = (int)$pdo->lastInsertId();
    // D: a completed sale WITH a real GL posting
    $ins->execute(['PHASE9-D', $synth_shift_id, 3000, 3000, 'cash', 'completed', 0]);
    $postedSaleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pos_sale_items (sale_id, product_id, product_name, quantity, unit_price, line_total)
                    VALUES (?, ?, 'Phase9 Test Item', 1, 3000, 3000)")->execute([$postedSaleId, $product['product_id']]);
    $glResult = postPosSale($pdo, $postedSaleId, 'cash', 3000.0, 0.0, 3000.0, 0.0, date('Y-m-d'), 'PHASE9-D', null, 4);
    $glResult['revenue'] ? pass('sale D posted to the GL (setup for the unposted-count check)') : fail('sale D failed to post: ' . json_encode($glResult));

    pass('4 synthetic pos_sales rows created (voided, return, unposted, GL-posted)');

    $extras = posShiftReportExtras($pdo, $synth_shift_id);

    ($extras['void_count'] === 1 && abs($extras['void_amount'] - 4000) < 0.01)
        ? pass("void_count/void_amount correct ({$extras['void_count']}, {$extras['void_amount']})")
        : fail('void summary wrong: ' . json_encode($extras));
    $extras['return_count'] === 1 ? pass('return_count correct (1)') : fail('return_count wrong: ' . $extras['return_count']);
    $extras['unposted_count'] === 1 ? pass('unposted_count correctly flags ONLY sale C, not the GL-posted sale D')
        : fail("unposted_count wrong — expected 1, got {$extras['unposted_count']}");

    $pdo->rollBack();
    pass('transaction rolled back');

    $after = (int)$pdo->query("SELECT COUNT(*) FROM pos_sales WHERE shift_id = $synth_shift_id")->fetchColumn();
    $after === 0 ? pass('no synthetic pos_sales rows persisted after rollback') : fail("rollback left $after pos_sales rows");
    $afterJe = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id = $postedSaleId AND entity_type IN ('pos_sale','pos_cogs')")->fetchColumn();
    $afterJe === 0 ? pass('no synthetic journal_entries rows persisted after rollback') : fail("rollback left $afterJe journal_entries rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

exit($failures === 0 ? 0 : 1);
