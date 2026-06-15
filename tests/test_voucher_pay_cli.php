<?php
/**
 * Payment Voucher — "Pay" form + GL posting — CLI test
 *   php tests/test_voucher_pay_cli.php
 *
 * Guards the voucher pay flow (api/account/update_voucher_status.php):
 *   - the "Pay" step records a real cash-out from a BANK/CASH account;
 *   - paid_from_account_id must be a bank/cash account (rejects a non-bank account);
 *   - it saves payment_date / payment_method / reference on the voucher;
 *   - it posts Dr Expense / Cr Paid-From bank to the GL, dated the PAYMENT date.
 *
 * Creates a synthetic approved voucher, drives the real endpoint, then tears down the
 * voucher + its GL posting (payment_vouchers is MyISAM — no rollback), leaving the
 * database as found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/financial_reports.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

$VOUCHER_ID = 0; $TXN_ID = 0;
function teardown(PDO $pdo): void {
    global $VOUCHER_ID, $TXN_ID;
    if ($TXN_ID) {
        require_once dirname(__DIR__) . '/core/payment_source.php';
        reverseOutflow($pdo, $TXN_ID);   // removes transactions + books_transactions + journal mirror
        $TXN_ID = 0;
    }
    if ($VOUCHER_ID) {
        // Any accrual entry posted at approval (entity voucher_accrual) — best-effort clear.
        $pdo->prepare("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE je.entity_type IN ('voucher_accrual','voucher_accrual_void') AND je.entity_id=?")->execute([$VOUCHER_ID]);
        $pdo->prepare("DELETE FROM journal_entries WHERE entity_type IN ('voucher_accrual','voucher_accrual_void') AND entity_id=?")->execute([$VOUCHER_ID]);
        $pdo->prepare("DELETE FROM payment_vouchers WHERE id=?")->execute([$VOUCHER_ID]);
        $VOUCHER_ID = 0;
    }
}
register_shutdown_function(function () use ($pdo) {
    teardown($pdo);
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$callPay = function (array $post) use ($root) {
    global $pdo;   // the endpoint relies on the global $pdo (top-level include in prod)
    $_POST = $post; $_FILES = []; $_SERVER['REQUEST_METHOD'] = 'POST';
    if (function_exists('csrf_token')) { $_POST['_csrf'] = csrf_token(); $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token(); }
    $prev = error_reporting(error_reporting() & ~E_WARNING & ~E_NOTICE);
    ob_start(); require $root . '/api/account/update_voucher_status.php'; $raw = ob_get_clean();
    error_reporting($prev);
    return json_decode($raw, true);
};

// ─────────────────────────────────────────────────────────────────────────
section('1. Setup: a synthetic APPROVED voucher');
$exp  = expenseAccounts($pdo);  $expAcc  = (int)$exp[0]['account_id'];
$cb   = cashBankAccounts($pdo); $bankAcc = (int)$cb[0]['account_id'];
$amount = 84000.00;
$pdo->prepare("INSERT INTO payment_vouchers (voucher_number, vouch_date, payee_name, amount, payment_method, expense_account_id, status, prepared_by)
               VALUES (?, ?, ?, ?, 'cash', ?, 'approved', ?)")
    ->execute(['PV-TST-' . time(), '2026-06-10', 'Test Payee', $amount, $expAcc, $_SESSION['user_id']]);
$VOUCHER_ID = (int)$pdo->lastInsertId();
echo "   voucher #$VOUCHER_ID amount=" . money($amount) . " expense=#$expAcc bank=#$bankAcc\n";
$VOUCHER_ID > 0 ? pass("approved voucher created (#$VOUCHER_ID)") : fail('could not create voucher');

// ─────────────────────────────────────────────────────────────────────────
section('2. A non-bank "Paid From" is rejected');
$bad = $callPay(['id' => $VOUCHER_ID, 'status' => 'paid', 'paid_from_account_id' => $expAcc, 'payment_date' => '2026-06-15']);
($bad && empty($bad['success']) && stripos($bad['message'] ?? '', 'cash/bank') !== false)
    ? pass('non-bank Paid From rejected ("must be an active cash/bank account")')
    : fail('non-bank Paid From should be rejected; got: ' . json_encode($bad));
// status must still be approved (not paid)
(($pdo->query("SELECT status FROM payment_vouchers WHERE id=$VOUCHER_ID")->fetchColumn()) === 'approved')
    ? pass('voucher stayed approved after the rejected attempt') : fail('voucher status changed on a rejected pay');

// ─────────────────────────────────────────────────────────────────────────
section('3. Pay with a real bank account + payment date → saved + posted to GL');
$payDate = '2026-06-15';
$res = $callPay(['id' => $VOUCHER_ID, 'status' => 'paid', 'paid_from_account_id' => $bankAcc,
                 'payment_date' => $payDate, 'payment_method' => 'bank_transfer', 'payment_reference' => 'CHQ-001']);
(!empty($res['success'])) ? pass('pay endpoint succeeded: ' . ($res['message'] ?? '')) : fail('pay failed: ' . json_encode($res));

$v = $pdo->query("SELECT status, paid_from_account_id, payment_date, payment_method, reference_number, transaction_id
                    FROM payment_vouchers WHERE id=$VOUCHER_ID")->fetch(PDO::FETCH_ASSOC);
($v['status'] === 'paid') ? pass('voucher status = paid') : fail("status = {$v['status']}");
((int)$v['paid_from_account_id'] === $bankAcc) ? pass('paid_from_account_id saved (the bank)') : fail('paid_from not saved');
($v['payment_date'] === $payDate) ? pass("payment_date saved ($payDate)") : fail("payment_date = {$v['payment_date']}");
($v['payment_method'] === 'bank_transfer') ? pass('payment_method saved') : fail("method = {$v['payment_method']}");
($v['reference_number'] === 'CHQ-001') ? pass('reference saved') : fail("ref = {$v['reference_number']}");
$TXN_ID = (int)($v['transaction_id'] ?? 0);
($TXN_ID > 0) ? pass("GL transaction linked (#$TXN_ID)") : fail('no transaction_id on the voucher');

// ─────────────────────────────────────────────────────────────────────────
section('4. The GL entry: Cr the Paid-From bank, dated the payment date, balanced');
$je = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=$TXN_ID AND status='posted'")->fetchColumn();
if ($je) {
    pass("posting mirrored into the GL (entry #$je)");
    $hdr = $pdo->query("SELECT entry_date FROM journal_entries WHERE entry_id=$je")->fetch(PDO::FETCH_ASSOC);
    (substr((string)$hdr['entry_date'],0,10) === $payDate) ? pass("GL entry dated the PAYMENT date ($payDate)") : fail("GL dated {$hdr['entry_date']}, expected $payDate");
    $rows = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$je")->fetchAll(PDO::FETCH_ASSOC);
    $dr=0;$cr=0;$crBank=0;
    foreach($rows as $r){ $t=(float)$r['amount']; if($r['type']==='debit')$dr+=$t; else {$cr+=$t; if((int)$r['account_id']===$bankAcc)$crBank+=$t;} }
    (abs($dr-$cr)<0.01) ? pass("balanced (Dr ".money($dr)." = Cr ".money($cr).")") : fail("unbalanced Dr $dr vs Cr $cr");
    (abs($crBank-$amount)<0.01) ? pass('the Paid-From bank was CREDITED the amount (cash out)') : fail("bank credit $crBank != $amount");
} else {
    fail('payment did not reach the GL (no journal mirror)');
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Teardown leaves the books balanced');
teardown($pdo);
$gone = (int)$pdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE id=$VOUCHER_ID")->fetchColumn();
($gone === 0) ? pass('voucher + GL posting removed (clean teardown)') : fail('teardown left rows');
$g = assertLedgerBalanced($pdo);
$g['ledger_balanced'] ? pass('ledger Σ Dr = Σ Cr after teardown') : fail('ledger out of balance after teardown');
