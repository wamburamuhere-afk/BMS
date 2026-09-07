<?php
/**
 * POS Phase 8 — Register/Till model — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase8_registers_cli.php
 *
 * Verifies:
 *   1. All touched/added files lint-clean.
 *   2. Wiring source patterns:
 *      - open_shift.php validates + stores register_id, has csrf_check(), rejects
 *        a register already staffed by another active shift
 *      - close_shift.php has csrf_check(), uses posShiftTenderTotals(), persists
 *        cash_in/cash_out/closed_by alongside the totals
 *      - process_sale.php maps 'split' -> 'mixed' for the DB enum, persists
 *        payment_details + register_id/register_name
 *      - print_receipt.php joins pos_registers via the sale's own register_id
 *      - register CRUD endpoints (get/save/toggle) are permission- and
 *        CSRF-gated correctly
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation):
 *      a) buildSplitPaymentLegs() maps the split modal's keys onto the real
 *         payment_method enum names, skips zero/blank legs, returns null for
 *         a non-split/empty input
 *      b) posShiftTenderTotals() correctly buckets cash/card/mobile/credit/mixed/
 *         voided/returned synthetic sales for one shift
 *      c) rollback leaves no trace
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/pos_shift_reporting.php";

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

$files = [
    'api/pos/get_registers.php', 'api/pos/save_register.php', 'api/pos/toggle_register_status.php',
    'api/pos/open_shift.php', 'api/pos/close_shift.php', 'api/pos/process_sale.php', 'api/pos/print_receipt.php',
    'core/pos_shift_reporting.php',
    'app/constant/settings/pos_config_settings.php', 'app/bms/pos/pos_modals_new.php', 'app/bms/pos/pos_scripts_new.php',
];

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
$openSrc  = file_get_contents("$root/api/pos/open_shift.php");
$closeSrc = file_get_contents("$root/api/pos/close_shift.php");
$saleSrc  = file_get_contents("$root/api/pos/process_sale.php");
$rcptSrc  = file_get_contents("$root/api/pos/print_receipt.php");
$getRegSrc   = file_get_contents("$root/api/pos/get_registers.php");
$saveRegSrc  = file_get_contents("$root/api/pos/save_register.php");
$toggleRegSrc = file_get_contents("$root/api/pos/toggle_register_status.php");

$checks = [
    [$openSrc,  "csrf_check();",                                   'open_shift.php has csrf_check()'],
    [$openSrc,  "FROM pos_registers WHERE register_id = ? AND status = 'active'", 'open_shift.php validates the register is active'],
    [$openSrc,  "is already in an active shift with another cashier",  'open_shift.php rejects a register already staffed'],
    [$openSrc,  "INSERT INTO cash_register_shifts",                'open_shift.php still inserts the shift row'],
    [$closeSrc, "csrf_check();",                                   'close_shift.php has csrf_check()'],
    [$closeSrc, "posShiftTenderTotals(\$pdo, \$shift_id)",          'close_shift.php calls posShiftTenderTotals()'],
    [$closeSrc, "cash_in = ?",                                     'close_shift.php persists cash_in'],
    [$closeSrc, "closed_by = ?",                                   'close_shift.php persists closed_by'],
    [$saleSrc,  "\$db_payment_method = (\$payment_method === 'split') ? 'mixed' : \$payment_method;", "process_sale.php maps 'split' -> 'mixed' for the DB enum"],
    [$saleSrc,  "buildSplitPaymentLegs(\$split_details)",           'process_sale.php calls buildSplitPaymentLegs()'],
    [$saleSrc,  "register_id, register_name",                      'process_sale.php INSERT includes register_id/register_name'],
    [$rcptSrc,  "LEFT JOIN pos_registers r ON s.register_id = r.register_id", "print_receipt.php joins pos_registers via the sale's own register_id"],
    [$getRegSrc, "canView('pos')",                                 'get_registers.php gated by canView(pos)'],
    [$saveRegSrc, "canEdit('pos_config_settings')",                'save_register.php gated by canEdit(pos_config_settings)'],
    [$saveRegSrc, "csrf_check();",                                 'save_register.php has csrf_check()'],
    [$toggleRegSrc, "canEdit('pos_config_settings')",              'toggle_register_status.php gated by canEdit(pos_config_settings)'],
    [$toggleRegSrc, "csrf_check();",                               'toggle_register_status.php has csrf_check()'],
    [$toggleRegSrc, "cash_register_shifts WHERE register_id = ? AND status = 'active'", 'toggle_register_status.php blocks deactivating a register with an open shift'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3a. buildSplitPaymentLegs() — pure function');
// ─────────────────────────────────────────────────────────────────────────
$legs = json_decode(buildSplitPaymentLegs(['cash' => 1000, 'mobile' => 2000, 'bank' => 500, 'card' => 0]), true)['legs'] ?? null;
($legs && abs($legs['cash'] - 1000) < 0.01 && abs($legs['mobile_money'] - 2000) < 0.01 && abs($legs['bank_transfer'] - 500) < 0.01 && !isset($legs['card']))
    ? pass('maps cash/mobile/bank to real enum names, drops the zero card leg')
    : fail('leg mapping wrong: ' . json_encode($legs));

buildSplitPaymentLegs(null) === null ? pass('null input -> null') : fail('null input did not return null');
buildSplitPaymentLegs(['cash' => 0, 'card' => 0]) === null ? pass('all-zero legs -> null') : fail('all-zero legs did not return null');

// ─────────────────────────────────────────────────────────────────────────
section('3b. posShiftTenderTotals() — live-DB (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$synth_shift_id = 90000303;
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_id, shift_code, user_id, register_id, starting_cash, status, created_at)
                    VALUES (?, 'PHASE8-TEST-SHIFT', 4, 1, 0, 'active', NOW())")
        ->execute([$synth_shift_id]);
    pass('synthetic shift row created');

    $mixedLegs = json_encode(['legs' => ['cash' => 3000, 'card' => 2000]]);
    $rows = [
        // [payment_method, grand_total, is_return_sale, sale_status, payment_details]
        ['cash',         10000, 0, 'completed', null],
        ['card',          5000, 0, 'completed', null],
        ['mobile_money',  4000, 0, 'completed', null],
        ['credit',        1500, 0, 'completed', null],
        ['mixed',         5000, 0, 'completed', $mixedLegs],
        ['cash',          9999, 0, 'voided',    null],   // must be excluded entirely
        ['cash',           800, 1, 'refunded',  null],   // a return -> total_refunds only
    ];
    $ins = $pdo->prepare("INSERT INTO pos_sales (receipt_number, shift_id, user_id, subtotal, tax_amount, discount_amount, grand_total, payment_method, sale_status, payment_status, is_return_sale, payment_details, sale_date, created_at)
                           VALUES (?, ?, 4, ?, 0, 0, ?, ?, ?, 'paid', ?, ?, NOW(), NOW())");
    foreach ($rows as $i => [$method, $amt, $isReturn, $status, $details]) {
        $ins->execute(["PHASE8-RCPT-$i", $synth_shift_id, $amt, $amt, $method, $status, $isReturn, $details]);
    }
    pass('7 synthetic pos_sales rows created (incl. 1 voided, 1 return, 1 mixed)');

    $totals = posShiftTenderTotals($pdo, $synth_shift_id);

    // Expected: total_sales = 10000+5000+4000+1500+5000 = 25500 (voided excluded)
    // cash = 10000 (plain) + 3000 (mixed leg) = 13000
    // card = 5000 (plain) + 2000 (mixed leg) = 7000
    // mobile = 4000, credit = 1500, refunds = 800
    $expected = ['total_sales' => 25500.0, 'total_cash_sales' => 13000.0, 'total_card_sales' => 7000.0,
                 'total_mobile_sales' => 4000.0, 'total_credit_sales' => 1500.0, 'total_refunds' => 800.0];
    $ok = true;
    foreach ($expected as $k => $v) { if (abs(($totals[$k] ?? -1) - $v) > 0.01) $ok = false; }
    $ok ? pass('posShiftTenderTotals() buckets correctly: ' . json_encode($totals))
        : fail('totals wrong — expected ' . json_encode($expected) . ' got ' . json_encode($totals));

    $pdo->rollBack();
    pass('transaction rolled back');

    $after = (int)$pdo->query("SELECT COUNT(*) FROM pos_sales WHERE shift_id = $synth_shift_id")->fetchColumn();
    $after === 0 ? pass('no synthetic pos_sales rows persisted after rollback') : fail("rollback left $after pos_sales rows");
    $afterShift = (int)$pdo->query("SELECT COUNT(*) FROM cash_register_shifts WHERE shift_id = $synth_shift_id")->fetchColumn();
    $afterShift === 0 ? pass('no synthetic shift row persisted after rollback') : fail('rollback left the synthetic shift row');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3b threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('3c. pos_registers table sanity');
// ─────────────────────────────────────────────────────────────────────────
$seed = $pdo->query("SELECT register_id, register_name, status FROM pos_registers WHERE register_id = 1")->fetch(PDO::FETCH_ASSOC);
($seed && $seed['status'] === 'active') ? pass("seed register #1 ({$seed['register_name']}) present and active") : fail('seed register #1 missing or inactive — open_shift.php default would break');

// ─────────────────────────────────────────────────────────────────────────
section('3d. JS safeOutput() regression guard');
// ─────────────────────────────────────────────────────────────────────────
// Real bug found live (2026-09-07): safeOutput() is a per-page LOCAL JS
// convention in this codebase (each page defines its own copy — it is NOT a
// global helper from header.php), so calling it in a file that never defines
// it throws a ReferenceError at runtime. lint (php -l) and every wiring check
// above are blind to this — they don't execute the JS. This scans every
// touched-by-this-tranche file: if it calls safeOutput(, it must also define
// it locally (`function safeOutput`), or the call must not exist at all.
$jsSafetyFiles = [
    'app/bms/pos/pos.php', 'app/bms/pos/pos_scripts_new.php', 'app/bms/pos/pos_modals_new.php',
    'app/bms/pos/shift_history.php', 'app/bms/pos/zreport.php', 'app/bms/pos/customer_display.php',
    'app/constant/settings/pos_config_settings.php',
];
foreach ($jsSafetyFiles as $f) {
    $src = file_get_contents("$root/$f");
    $callsIt   = strpos($src, 'safeOutput(') !== false;
    $definesIt = strpos($src, 'function safeOutput') !== false;
    if (!$callsIt) { pass("$f: does not call safeOutput() — nothing to check"); continue; }
    $definesIt
        ? pass("$f: calls safeOutput() AND defines it locally")
        : fail("$f: calls safeOutput() but never defines it — ReferenceError at runtime");
}

exit($failures === 0 ? 0 : 1);
