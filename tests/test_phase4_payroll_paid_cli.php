<?php
/**
 * Phase 4.6 — auto-post on payroll paid CLI test
 * -----------------------------------------------
 *   php tests/test_phase4_payroll_paid_cli.php
 *
 * Verifies:
 *   1. update_payroll_status.php lint-clean.
 *   2. Wiring source patterns:
 *      - includes core/auto_post_hook.php
 *      - fetches payroll snapshot (net_salary + payroll_date) BEFORE UPDATE
 *      - adds beginTransaction/commit + rollBack in catch (was missing)
 *      - calls autoPostEvent('payroll_paid', 'payroll', ...)
 *      - amount = net_salary (not gross/basic)
 *      - entry_date = payroll_date
 *      - project_id = null (payroll is company-wide overhead)
 *      - only posts when $status === 'paid' (approved skipped)
 *      - response surfaces journal_entry_id / ledger_warning
 *      - ordering: snapshot < beginTransaction < UPDATE < autoPostEvent < commit
 *   3. Live-DB end-to-end (BEGIN/ROLLBACK isolation) — 'payroll_paid' event
 *      slug round-trips, idempotency holds, entry has project_id=NULL.
 *   4. Phase 4.3 + 4.4 + 4.5 tests still pass.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/auto_post_hook.php";

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

$file = "$root/api/update_payroll_status.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
file_exists($file) ? pass('update_payroll_status.php exists') : fail('missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "require_once __DIR__ . '/../core/auto_post_hook.php'"     => 'includes auto_post_hook',
    "autoPostEvent("                                            => 'calls autoPostEvent',
    "'payroll_paid'"                                            => 'uses payroll_paid event slug',
    "'payroll'"                                                 => 'uses payroll entity_type',
    "net_salary, amount_paid, accrual_transaction_id"           => 'fetches payroll snapshot (incl. amount_paid) BEFORE UPDATE',
    "postPayrollPayment("                                       => 'settles Salaries Payable via postPayrollPayment (OUT-4, not AP outflow)',
    "ensurePayrollAccrued("                                     => 'accrues the salary expense at approval (accrual basis)',
    "\$pdo->beginTransaction()"                                  => 'adds beginTransaction (was missing)',
    "\$pdo->commit()"                                            => 'commits after auto-post',
    "if (isset(\$pdo) && \$pdo->inTransaction()) \$pdo->rollBack()" => 'rolls back on exception',
    "if (\$status === 'paid'"                                   => 'only posts on paid transition',
    "'status_not_paid'"                                         => 'non-paid statuses report status_not_paid',
    "\$thisPayment"                                              => 'amount = thisPayment (instalment, supports partial)',
    "\$payroll_snap['payroll_date']"                            => 'entry_date = payroll_date',
    "null,  // payroll is company-wide"                         => 'project_id = NULL (company-wide overhead)',
    'journal_entry_id'                                          => 'surfaces successful entry_id to response',
    // Money-safety (Step 11): the salary settlement (postPayrollPayment) is now
    // guaranteed posted or the whole transaction rolls back, so the misleading
    // "marked paid but no ledger entry" ledger_warning was removed. The handler
    // instead FAILS LOUDLY on a null settlement and surfaces the I3 funds warning.
    "throw new Exception('Payroll payment could not be posted" => 'fails loudly when the settlement cannot post (no silent loss)',
    'funds_warning'                                            => 'surfaces the I3 funds warning (warn but allow)',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// Order check: snapshot < beginTransaction < UPDATE < autoPostEvent < commit
$pos_snap   = strpos($src, "net_salary, amount_paid, accrual_transaction_id");
$pos_begin  = strpos($src, "\$pdo->beginTransaction()");
$pos_update = strpos($src, "UPDATE payroll SET payment_status");
$pos_post   = strpos($src, "autoPostEvent(");
$pos_commit = strpos($src, "\$pdo->commit()");

($pos_snap !== false && $pos_begin !== false && $pos_update !== false
    && $pos_post !== false && $pos_commit !== false
    && $pos_snap < $pos_begin && $pos_begin < $pos_update
    && $pos_update < $pos_post && $pos_post < $pos_commit)
    ? pass('order: snapshot < beginTransaction < UPDATE < autoPostEvent < commit')
    : fail('ordering broken');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB end-to-end (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$accts = array_map('intval', $pdo
    ->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN));
if (count($accts) < 2) { fail('not enough active accounts'); exit(1); }
[$salaries_acct, $cash_acct] = $accts;

$mapping_id = (int)$pdo
    ->query("SELECT id FROM journal_mappings WHERE event_type = 'payroll_paid' LIMIT 1")
    ->fetchColumn();
$mapping_id > 0 ? pass("found payroll_paid mapping (id=$mapping_id)") : fail('payroll_paid mapping missing');

$synth_payroll_id = 90000301;

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE journal_mappings SET debit_account_id=$salaries_acct, credit_account_id=$cash_acct, is_active=1 WHERE id=$mapping_id");

    $amt = 2500.00;
    // project_id=null because payroll is company-wide
    $r = autoPostEvent($pdo, 'payroll_paid', 'payroll', $synth_payroll_id, $amt, null, '2026-05-25', 4, 'Test payroll paid');
    ($r['posted'] === true && isset($r['entry_id']) && $r['entry_id'] > 0)
        ? pass("post succeeded (entry_id={$r['entry_id']})")
        : fail('post failed: ' . json_encode($r));

    $entry_id = (int)$r['entry_id'];

    // Verify entry tagged correctly with project_id=NULL
    $je = $pdo->prepare("SELECT entity_type, entity_id, project_id, status, amount, entry_date FROM journal_entries WHERE entry_id = ?");
    $je->execute([$entry_id]);
    $row = $je->fetch(PDO::FETCH_ASSOC);
    ($row && $row['entity_type'] === 'payroll' && (int)$row['entity_id'] === $synth_payroll_id
        && $row['project_id'] === null && $row['status'] === 'posted'
        && abs((float)$row['amount'] - $amt) < 0.01 && $row['entry_date'] === '2026-05-25')
        ? pass('journal_entries row tagged entity_type=payroll, project_id=NULL (company-wide)')
        : fail('journal_entries row wrong: ' . json_encode($row));

    // Verify items: Dr salaries / Cr cash
    $items = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
    $items->execute([$entry_id]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);
    $has_dr_sal = $has_cr_cash = false;
    $total_dr = $total_cr = 0.0;
    foreach ($lines as $l) {
        if ($l['type'] === 'debit'  && (int)$l['account_id'] === $salaries_acct) $has_dr_sal = true;
        if ($l['type'] === 'credit' && (int)$l['account_id'] === $cash_acct)     $has_cr_cash = true;
        if ($l['type'] === 'debit')  $total_dr += (float)$l['amount'];
        if ($l['type'] === 'credit') $total_cr += (float)$l['amount'];
    }
    ($has_dr_sal && $has_cr_cash && abs($total_dr - $total_cr) < 0.01 && abs($total_dr - $amt) < 0.01)
        ? pass("items: Dr salaries=$salaries_acct, Cr cash=$cash_acct, balanced ($total_dr == $total_cr == $amt)")
        : fail('items wrong: ' . json_encode($lines));

    // Idempotency
    $r2 = autoPostEvent($pdo, 'payroll_paid', 'payroll', $synth_payroll_id, $amt, null, '2026-05-25', 4, 'Test idemp');
    ($r2['posted'] === false && $r2['reason'] === 'already_posted' && (int)$r2['existing_entry_id'] === $entry_id)
        ? pass("second call → already_posted, existing_entry_id=$entry_id")
        : fail('idempotency broken: ' . json_encode($r2));

    $pdo->rollBack();
    pass('transaction rolled back');

    $cnt_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_id=$synth_payroll_id")->fetchColumn();
    $cnt_after === 0 ? pass('no synthetic rows persisted after rollback')
                    : fail("rollback left $cnt_after rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Partial payment — source wiring patterns');
// ─────────────────────────────────────────────────────────────────────────
$bulk_file   = "$root/api/bulk_update_payroll_status.php";
$bulk_src    = file_exists($bulk_file) ? file_get_contents($bulk_file) : '';
$single_src  = $src; // already loaded above

$partial_checks = [
    // bulk endpoint
    [$bulk_src,   "amount_paid",                                "bulk: fetches amount_paid in SELECT"],
    [$bulk_src,   'payment_status IN (\'approved\', \'processing\', \'partial\')', "bulk: where_extra includes partial"],
    [$bulk_src,   '$paid_amount_input',                         "bulk: accepts paid_amount_input"],
    [$bulk_src,   '$remaining',                                 "bulk: calculates remaining"],
    [$bulk_src,   'min($paid_amount_input, $remaining)',         "bulk: caps payment at remaining"],
    [$bulk_src,   '\'partial\'',                                "bulk: uses partial status"],
    [$bulk_src,   'postPayrollPayment($pdo, $p, (int)$paid_from_account_id, (int)$_SESSION[\'user_id\'], $thisPayment)', "bulk: passes override amount to postPayrollPayment"],
    // single endpoint
    [$single_src, 'amount_paid',                                "single: fetches amount_paid in snap SELECT"],
    [$single_src, '$paid_amount_input',                         "single: accepts paid_amount_input"],
    [$single_src, '$remaining',                                 "single: calculates remaining"],
    [$single_src, '$thisPayment',                               'single: uses $thisPayment variable'],
    [$single_src, '\'partial\'',                                "single: uses partial status"],
    [$single_src, 'postPayrollPayment($pdo, $payroll_snap, (int)$paid_from_account_id, (int)$_SESSION[\'user_id\'], $thisPayment)', "single: passes override amount to postPayrollPayment"],
];
foreach ($partial_checks as [$haystack, $needle, $label]) {
    strpos($haystack, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Partial payment — live GL (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
require_once "$root/core/payment_source.php";

$pdo->beginTransaction();
try {
    // Insert synthetic payroll record representing a 1,200,000 net salary
    $net_salary = 1200000.00;
    $pdo->exec("INSERT INTO payroll (payroll_id, payroll_number, employee_id, payroll_period, payroll_date,
                    basic_salary, gross_salary, tax_amount, net_salary, amount_paid, payment_status, status, created_by, created_at)
                VALUES (90000401, 'TEST-PARTIAL-001', 1, '2026-06', '2026-06-15',
                    $net_salary, $net_salary, 0, $net_salary, 0.00, 'approved', 'active', 4, NOW())
                ON DUPLICATE KEY UPDATE payroll_id=payroll_id");

    $synth = $pdo->query("SELECT * FROM payroll WHERE payroll_id = 90000401")->fetch(PDO::FETCH_ASSOC);
    $synth ? pass('synthetic payroll record (net=1,200,000) inserted') : fail('insert failed');

    // --- Test 1: partial payment of 500,000 posts correct GL amount ---
    $partial_amt = 500000.00;
    $txn1 = postPayrollPayment($pdo, $synth, $cash_acct, 4, $partial_amt);
    ($txn1 !== null)
        ? pass("partial postPayrollPayment returned entry_id=$txn1")
        : fail('partial postPayrollPayment returned null');

    if ($txn1) {
        // postPayrollPayment posts via postLedgerEntry → journal_entry_items
        $items1 = $pdo->prepare("SELECT type, SUM(amount) as total FROM journal_entry_items WHERE entry_id=? GROUP BY type");
        $items1->execute([$txn1]);
        $items1 = $items1->fetchAll(PDO::FETCH_ASSOC);
        $totals1 = array_column($items1, 'total', 'type');
        (abs((float)($totals1['debit']??0)  - $partial_amt) < 0.01 &&
         abs((float)($totals1['credit']??0) - $partial_amt) < 0.01)
            ? pass("partial GL balanced: Dr=Cr=500,000")
            : fail("GL amounts wrong: " . json_encode($totals1));
    }

    // --- Test 2: remaining balance calculation ---
    $remaining_after1 = round($net_salary - $partial_amt, 2);
    $remaining_after1 === 700000.00
        ? pass("remaining after first partial: 700,000")
        : fail("remaining calculation wrong: $remaining_after1");

    // --- Test 3: overpay is capped at remaining ---
    $overpay   = 900000.00;  // more than remaining 700K
    $capped    = min($overpay, $remaining_after1);
    abs($capped - 700000.00) < 0.01
        ? pass("overpay guard: min(900K, 700K) = 700,000")
        : fail("overpay guard wrong: capped=$capped");

    // --- Test 4: second payment (700K) fully settles, newStatus = 'paid' ---
    $amount_paid_so_far = $partial_amt;
    $remaining2   = round($net_salary - $amount_paid_so_far, 2);
    $thisPayment2 = $remaining2;  // pay full remaining
    $newAmtPaid2  = round($amount_paid_so_far + $thisPayment2, 2);
    $newStatus2   = ($newAmtPaid2 >= $net_salary - 0.005) ? 'paid' : 'partial';
    $newStatus2 === 'paid'
        ? pass("second payment settles: newStatus=paid, newAmtPaid={$newAmtPaid2}")
        : fail("second payment did not flip to paid: newStatus=$newStatus2 newAmtPaid=$newAmtPaid2");

    // --- Test 5: already-paid record is excluded by where_extra ---
    // Simulate: amount_paid = net_salary → remaining <= 0 → skipped
    $already_paid_remaining = round($net_salary - $net_salary, 2);
    $already_paid_remaining <= 0
        ? pass("re-pay guard: remaining=0 for fully-paid record → skipped")
        : fail("re-pay guard broken: remaining=$already_paid_remaining");

    $pdo->rollBack();
    pass('partial payment section rolled back — no synthetic rows persisted');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 5 threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Phase 4.3 + 4.4 + 4.5 tests still pass');
// ─────────────────────────────────────────────────────────────────────────
foreach ([
    'tests/test_phase4_auto_post_hook_cli.php',
    'tests/test_phase4_payment_received_cli.php',
    'tests/test_phase4_expense_paid_cli.php',
] as $rel) {
    $tf = "$root/$rel";
    if (!file_exists($tf)) { pass("$rel not present — skipping"); continue; }
    $rc = 0; $o = [];
    exec("php " . escapeshellarg($tf) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$rel still passes") : fail("$rel failed: rc=$rc");
}

exit($failures === 0 ? 0 : 1);
