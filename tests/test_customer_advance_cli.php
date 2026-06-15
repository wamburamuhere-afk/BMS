<?php
/**
 * IN-7 — Customer advance / deposit → GL — CLI test
 *   php tests/test_customer_advance_cli.php
 *
 * Guards core/customer_advance.php + the record/apply endpoints. A customer advance
 * is held as a liability and applied to invoices later, modelling WorkDo's Retainer:
 *   Receive:  Dr Bank / Cr Client Deposits (2-1600)
 *   Apply:    Dr Client Deposits / Cr Accounts Receivable (1-1200)
 *
 * Section 2 runs the real helpers inside a ROLLED-BACK transaction (payments,
 * payment_allocations, invoices, journal_entries are all InnoDB). Section 3 drives
 * the real endpoints and TEARS DOWN every artefact (incl. the MyISAM bank register),
 * restoring the touched invoice — so the database is left exactly as found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/customer_advance.php";
require_once "$root/core/financial_reports.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
require_once "$root/core/payment_source.php";
$cash = cashBankAccounts($pdo);
$bank = $cash ? (int)$cash[0]['account_id'] : 0;

// ─────────────────────────────────────────────────────────────────────────
section('1. Resolvers');
$dep = clientDepositsAccountId($pdo);
$ar  = arAccountId($pdo);
$dep ? pass("clientDepositsAccountId → #$dep (2-1600)") : fail('Client Deposits account missing');
$ar  ? pass("arAccountId → #$ar") : fail('AR account missing');
$bank ? pass("cash/bank account available → #$bank") : fail('no cash/bank account');

// A real invoice with a balance to apply against.
$invoice = $pdo->query("SELECT invoice_id, customer_id, grand_total, paid_amount, balance_due, status, project_id
                          FROM invoices WHERE balance_due > 1000 AND status IN ('approved','partial') ORDER BY invoice_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { fail('no invoice with balance to test application — aborting'); return; }
$custId = (int)$invoice['customer_id'];
$invId  = (int)$invoice['invoice_id'];
$invBal = (float)$invoice['balance_due'];

// ─────────────────────────────────────────────────────────────────────────
section('2. Core posting lifecycle (rolled back)');
$advAmount = round(min(50000.0, $invBal), 2);   // advance we can fully apply to the invoice
$applyAmt  = round($advAmount / 2, 2);

$beforePay = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$beforeJE  = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$grossBefore = customerAdvanceGross($pdo, $custId);

$pdo->beginTransaction();
try {
    // Synthetic advance receipt row + 'advance' marker.
    $pdo->prepare("INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, currency, payment_method, received_into_account_id, status, received_by, created_by)
                   VALUES (?, NULL, ?, ?, ?, 'TZS', 'cash', ?, 'completed', ?, ?)")
        ->execute(['ADV-TEST', $custId, '2026-06-14', $advAmount, $bank, $uid, $uid]);
    $payId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount)
                   VALUES (?, 'customer', 'advance', ?, ?)")->execute([$payId, $custId, $advAmount]);

    // Receive: Dr Bank / Cr Client Deposits
    $rc = postCustomerAdvanceReceipt($pdo, $payId, $bank, $advAmount, '2026-06-14', 'ADV-TEST', 'Advance test', null, $uid);
    (!empty($rc['posted']) && !empty($rc['entry_id'])) ? pass("advance receipt posted (entry #{$rc['entry_id']})") : fail('advance receipt did not post: ' . ($rc['reason'] ?? '?'));
    if (!empty($rc['entry_id'])) {
        $eid = (int)$rc['entry_id'];
        $L = $pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $et = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
        $dr=0;$cr=0;$drBank=0;$crDep=0;
        foreach($L as $l){$t=(float)$l['amount']; if($l['type']==='debit'){$dr+=$t; if((int)$l['account_id']===$bank)$drBank+=$t;} else {$cr+=$t; if((int)$l['account_id']===$dep)$crDep+=$t;}}
        (count($L)===2) ? pass('receipt has 2 legs') : fail('receipt legs != 2');
        (abs($dr-$cr)<0.01) ? pass("receipt balances (Dr ".money($dr)." = Cr ".money($cr).")") : fail("receipt unbalanced");
        (abs($drBank-$advAmount)<0.01) ? pass('Bank debited the advance') : fail('bank not debited');
        (abs($crDep-$advAmount)<0.01) ? pass('Client Deposits credited the advance (liability ↑)') : fail('client deposits not credited');
        ($et==='customer_advance') ? pass("entity_type='customer_advance'") : fail("entity_type=$et");
        $rc2 = postCustomerAdvanceReceipt($pdo, $payId, $bank, $advAmount, '2026-06-14', 'ADV-TEST', 'Advance test', null, $uid);
        (($rc2['reason']??'')==='already_posted' && (int)$rc2['entry_id']===$eid) ? pass('receipt idempotent') : fail('receipt not idempotent');
    }

    // Balances reflect the advance.
    (abs((customerAdvanceGross($pdo,$custId) - $grossBefore) - $advAmount) < 0.01) ? pass('customerAdvanceGross increased by the advance') : fail('gross wrong');
    (abs(advancePaymentAvailable($pdo,$payId) - $advAmount) < 0.01) ? pass('advancePaymentAvailable == full advance (nothing applied yet)') : fail('payment available wrong');

    // Apply half to the invoice: Dr Client Deposits / Cr AR
    $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount)
                   VALUES (?, 'customer', 'invoice', ?, ?)")->execute([$payId, $invId, $applyAmt]);
    $allocId = (int)$pdo->lastInsertId();
    $ap = postAdvanceApplication($pdo, $allocId, $applyAmt, '2026-06-14', null, $uid, 'test apply');
    (!empty($ap['posted']) && !empty($ap['entry_id'])) ? pass("advance application posted (entry #{$ap['entry_id']})") : fail('application did not post: ' . ($ap['reason'] ?? '?'));
    if (!empty($ap['entry_id'])) {
        $eid = (int)$ap['entry_id'];
        $L = $pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $drDep=0;$crAr=0;$dr=0;$cr=0;
        foreach($L as $l){$t=(float)$l['amount']; if($l['type']==='debit'){$dr+=$t; if((int)$l['account_id']===$dep)$drDep+=$t;} else {$cr+=$t; if((int)$l['account_id']===$ar)$crAr+=$t;}}
        (abs($dr-$cr)<0.01) ? pass("application balances (Dr ".money($dr)." = Cr ".money($cr).")") : fail('application unbalanced');
        (abs($drDep-$applyAmt)<0.01) ? pass('Client Deposits debited (liability ↓)') : fail('deposits not debited');
        (abs($crAr-$applyAmt)<0.01) ? pass('Accounts Receivable credited (invoice settled from deposit)') : fail('AR not credited');
    }

    // Available now reduced.
    (abs(advancePaymentAvailable($pdo,$payId) - ($advAmount - $applyAmt)) < 0.01) ? pass('advancePaymentAvailable reduced by the applied amount') : fail('available not reduced');

    // Reversal posts contras.
    $rev = reverseAdvanceApplication($pdo, $allocId, $uid);
    (!empty($rev['reversed'])) ? pass('application reversal posts a contra') : fail('application reversal failed: ' . ($rev['reason'] ?? '?'));
    $rev2 = reverseCustomerAdvanceReceipt($pdo, $payId, $uid);
    (!empty($rev2['reversed'])) ? pass('receipt reversal posts a contra') : fail('receipt reversal failed: ' . ($rev2['reason'] ?? '?'));

    // Ledger Dr=Cr holds.
    $g = assertLedgerBalanced($pdo);
    $g['ledger_balanced'] ? pass('Σ Dr = Σ Cr holds through the lifecycle') : fail('ledger Dr≠Cr');
} finally {
    $pdo->rollBack();
}

section('2b. Rolled back cleanly');
$afterPay = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
$afterJE  = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
($afterPay === $beforePay && $afterJE === $beforeJE) ? pass("no leak (payments {$beforePay} to {$afterPay}, journal {$beforeJE} to {$afterJE})") : fail("LEAK: payments {$beforePay} to {$afterPay}, journal {$beforeJE} to {$afterJE}");

// ─────────────────────────────────────────────────────────────────────────
section('3. Endpoints end-to-end (real commit, explicit teardown)');
// Capture invoice state to restore.
$inv0 = $pdo->query("SELECT paid_amount, balance_due, status, payment_date FROM invoices WHERE invoice_id=$invId")->fetch(PDO::FETCH_ASSOC);
$createdPayId = 0; $appliedAllocIds = [];
$runEndpoint = function (string $file, array $post) use ($root) {
    $_POST = $post; $_SERVER['REQUEST_METHOD'] = 'POST';
    if (function_exists('csrf_token')) { $_POST['_csrf'] = csrf_token(); $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token(); }
    $prev = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); require $root . '/api/account/' . $file; $raw = ob_get_clean();
    error_reporting($prev);
    return json_decode($raw, true);
};
try {
    $advAmt = round(min(40000.0, (float)$inv0['balance_due']), 2);
    $r = $runEndpoint('record_customer_advance.php', [
        'customer_id' => $custId, 'amount' => $advAmt, 'payment_date' => '2026-06-14',
        'payment_method' => 'cash', 'received_into_account_id' => $bank, 'reference_number' => 'INTEST',
    ]);
    (!empty($r['success']) && !empty($r['payment_id'])) ? pass("record endpoint: " . ($r['message'] ?? '')) : fail('record endpoint failed: ' . json_encode($r));
    $createdPayId = (int)($r['payment_id'] ?? 0);
    if ($createdPayId) {
        $je = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='customer_advance' AND entity_id=$createdPayId AND status='posted'")->fetchColumn();
        ($je === 1) ? pass('record endpoint posted Dr Bank / Cr Client Deposits') : fail('record endpoint did not post the GL entry');

        $applyAmt = round($advAmt / 2, 2);
        $r2 = $runEndpoint('apply_customer_advance.php', [
            'customer_id' => $custId, 'invoice_id' => $invId, 'amount' => $applyAmt, 'apply_date' => '2026-06-14',
        ]);
        (!empty($r2['success'])) ? pass("apply endpoint: " . ($r2['message'] ?? '')) : fail('apply endpoint failed: ' . json_encode($r2));
        $appAlloc = $pdo->query("SELECT id FROM payment_allocations WHERE payment_id=$createdPayId AND target_type='invoice'")->fetchAll(PDO::FETCH_COLUMN);
        $appliedAllocIds = array_map('intval', $appAlloc);
        $jeApp = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='advance_application' AND status='posted' AND entity_id IN (" . (empty($appliedAllocIds)?'0':implode(',',$appliedAllocIds)) . ")")->fetchColumn();
        ($jeApp >= 1) ? pass('apply endpoint posted Dr Client Deposits / Cr AR') : fail('apply endpoint did not post the application entry');

        $avail = customerAdvanceAvailable($pdo, $custId);
        is_numeric($avail) ? pass("customer available advance after apply = " . money($avail)) : fail('available not numeric');

        // ── Phase 3: the deposit surfaces in the reports + Balance Sheet ──
        section('3a. Phase 3 — deposit shows in AR aging, statement, Balance Sheet');
        // AR aging: customer row carries deposit + net_due; summary has deposits.
        $_GET = ['customer_id' => $custId, 'as_of_date' => '2026-12-31'];
        $prev = error_reporting(error_reporting() & ~E_WARNING);
        ob_start(); require $root . '/api/account/get_ar_aging.php'; $agRaw = ob_get_clean();
        error_reporting($prev);
        $ag = json_decode($agRaw, true);
        if (!empty($ag['success'])) {
            (isset($ag['summary']['deposits']) && (float)$ag['summary']['deposits'] >= $avail - 0.01)
                ? pass('AR aging summary.deposits reflects the held deposit (' . money((float)$ag['summary']['deposits']) . ')')
                : fail('AR aging summary.deposits missing/wrong: ' . json_encode($ag['summary']['deposits'] ?? null));
            $depRow = null;
            foreach ($ag['customers'] as $cc) { if ((int)$cc['customer_id'] === $custId) { $depRow = $cc; break; } }
            ($depRow && isset($depRow['deposit'], $depRow['net_due']))
                ? pass('AR aging customer row carries deposit + net_due')
                : fail('AR aging customer row missing deposit/net_due');
        } else {
            fail('AR aging endpoint failed: ' . substr($agRaw, 0, 120));
        }

        // Customer statement: deposit_balance present, advance line labelled.
        $_GET = ['customer_id' => $custId, 'date_from' => '2026-01-01', 'date_to' => '2026-12-31'];
        $prev = error_reporting(error_reporting() & ~E_WARNING);
        ob_start(); require $root . '/api/account/get_customer_statement.php'; $stRaw = ob_get_clean();
        error_reporting($prev);
        $st = json_decode($stRaw, true);
        if (!empty($st['success'])) {
            array_key_exists('deposit_balance', $st)
                ? pass('customer statement exposes deposit_balance (' . money((float)$st['deposit_balance']) . ')')
                : fail('customer statement missing deposit_balance');
            $hasAdv = false;
            foreach ($st['lines'] as $ln) { if (($ln['type'] ?? '') === 'advance') { $hasAdv = true; break; } }
            $hasAdv ? pass("statement labels the advance receipt as type='advance'") : fail('statement did not label the advance line');
        } else {
            fail('customer statement endpoint failed: ' . substr($stRaw, 0, 120));
        }

        // Balance Sheet: Client Deposits (2-1600) now carries the net deposit liability.
        // (Recompute the account id locally — requiring the report endpoints above
        // overwrites the outer $dep variable in this shared script scope.)
        $depAcctId = clientDepositsAccountId($pdo);
        $bs = glBalanceSheet($pdo, '2026-12-31');
        $depOnBs = 0.0;
        foreach ($bs['liabilities'] as $liab) { if ((int)($liab['account_id'] ?? 0) === (int)$depAcctId) $depOnBs = (float)$liab['amount']; }
        ($depOnBs >= $avail - 0.01)
            ? pass('Balance Sheet shows Client Deposits as a liability (' . money($depOnBs) . ')')
            : fail('Client Deposits not on the Balance Sheet (got ' . money($depOnBs) . ', expected ≥ ' . money($avail) . ')');
    }
} finally {
    // Restore the invoice FIRST so a teardown hiccup can never leave it modified.
    $pdo->prepare("UPDATE invoices SET paid_amount=?, balance_due=?, status=?, payment_date=? WHERE invoice_id=?")
        ->execute([$inv0['paid_amount'], $inv0['balance_due'], $inv0['status'], $inv0['payment_date'], $invId]);
    // Then remove the GL entries, allocations, payment row, and bank register row.
    $entities = [];
    if ($createdPayId) $entities[] = ['customer_advance', $createdPayId];
    foreach ($appliedAllocIds as $aid) $entities[] = ['advance_application', $aid];
    foreach ($entities as [$etype, $eid]) {
        $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id IN (SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=?)")->execute([$etype, $eid]);
        $pdo->prepare("DELETE FROM journal_entries WHERE entity_type=? AND entity_id=?")->execute([$etype, $eid]);
    }
    if ($createdPayId) {
        $num = $pdo->query("SELECT payment_number FROM payments WHERE payment_id=$createdPayId")->fetchColumn();
        $pdo->prepare("DELETE FROM payment_allocations WHERE payment_id=?")->execute([$createdPayId]);
        $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$createdPayId]);
        if ($num) $pdo->prepare("DELETE FROM bank_transactions WHERE reference_number=?")->execute([$num]);
    }
}

section('3b. Teardown restored the books');
$invNow = $pdo->query("SELECT balance_due, status FROM invoices WHERE invoice_id=$invId")->fetch(PDO::FETCH_ASSOC);
(abs((float)$invNow['balance_due'] - (float)$inv0['balance_due']) < 0.01 && $invNow['status'] === $inv0['status']) ? pass('invoice restored to its original balance/status') : fail('invoice not restored');
$leakPay = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_number LIKE 'ADV-2026%' AND reference_number='INTEST'")->fetchColumn();
($leakPay === 0) ? pass('no advance payment rows leaked') : fail("$leakPay advance payment rows leaked");
$g = assertLedgerBalanced($pdo);
$g['ledger_balanced'] ? pass('ledger Σ Dr = Σ Cr after teardown') : fail('ledger out of balance after teardown');
