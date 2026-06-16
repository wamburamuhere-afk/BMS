<?php
/**
 * OUT-7 — GRN → Inventory posting — CLI test
 *   php tests/test_grn_posting_cli.php
 *
 * Guards core/purchase_posting.php: a GRN approval posts a balanced
 * Dr Inventory / Cr Accounts Payable entry into the canonical ledger, idempotently,
 * crediting the SAME AP account the supplier payment debits. Runs the real
 * postGrnReceipt() against a real receipt inside a ROLLED-BACK transaction, so the
 * database is left untouched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/purchase_posting.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/financial_reports.php";
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

// ─────────────────────────────────────────────────────────────────────────
section('1. Control accounts resolve, and GRN-credit == payment-debit AP account');
$inv = inventoryAccountId($pdo);
$ap  = apAccountId($pdo);
$payAp = defaultPayableAccountId($pdo);
$inv ? pass("inventoryAccountId → #$inv") : fail('inventoryAccountId returned null (Inventory account missing)');
$ap  ? pass("apAccountId → #$ap")        : fail('apAccountId returned null (AP account missing)');
($ap && $payAp && (int)$ap === (int)$payAp)
    ? pass("AP credited by GRN (#$ap) == AP debited by payments (#$payAp) — nets correctly")
    : fail("AP mismatch: GRN credits #$ap but payments debit #" . ($payAp ?? 'NULL') . " — AP would never net");

// ─────────────────────────────────────────────────────────────────────────
section('2. postGrnReceipt posts a balanced Dr Inventory / Cr AP (rolled back)');
$receipt = $pdo->query("
    SELECT pr.receipt_id, pr.receipt_number, pr.receipt_date, pr.project_id,
           COALESCE(SUM(ri.quantity_received*ri.unit_price),0) val
      FROM purchase_receipts pr JOIN receipt_items ri ON ri.receipt_id=pr.receipt_id
  GROUP BY pr.receipt_id HAVING val>0 ORDER BY val DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$receipt) {
    fail('no receipt with items found in this database — cannot run the functional test');
    return;
}
$rid   = (int)$receipt['receipt_id'];
$val   = round((float)$receipt['val'], 2);
$uid   = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
echo "   using receipt #$rid {$receipt['receipt_number']}  value=" . money($val) . "\n";

$before = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='grn' AND status='posted'")->fetchColumn();

$pdo->beginTransaction();
try {
    $res = postGrnReceipt($pdo, $rid, $val, $receipt['receipt_date'], $receipt['project_id'] ? (int)$receipt['project_id'] : null, $uid, $receipt['receipt_number']);
    (!empty($res['posted']) && !empty($res['entry_id'])) ? pass("posted (entry #{$res['entry_id']})") : fail("did not post: reason=" . ($res['reason'] ?? '?'));

    if (!empty($res['entry_id'])) {
        $eid = (int)$res['entry_id'];
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);
        $hdr = $pdo->query("SELECT entity_type, status FROM journal_entries WHERE entry_id=$eid")->fetch(PDO::FETCH_ASSOC);

        (count($lines) === 2) ? pass('entry has exactly 2 lines') : fail('entry has ' . count($lines) . ' lines (want 2)');
        $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
        foreach ($lines as $l) { if ($l['type']==='debit'){$dr=(float)$l['amount'];$drAcc=(int)$l['account_id'];} else {$cr=(float)$l['amount'];$crAcc=(int)$l['account_id'];} }
        (abs($dr - $cr) < 0.01) ? pass("balanced (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("unbalanced Dr $dr vs Cr $cr");
        (abs($dr - $val) < 0.01) ? pass('amount equals goods value') : fail("amount $dr != goods value $val");
        ($drAcc === (int)$inv) ? pass('debit is the Inventory account') : fail("debit acct #$drAcc != Inventory #$inv");
        ($crAcc === (int)$ap)  ? pass('credit is the AP account')       : fail("credit acct #$crAcc != AP #$ap");
        (($hdr['entity_type'] ?? '') === 'grn') ? pass("entity_type='grn'") : fail("entity_type=" . ($hdr['entity_type'] ?? 'null'));

        // Idempotency within the same tx
        $res2 = postGrnReceipt($pdo, $rid, $val, $receipt['receipt_date'], $receipt['project_id'] ? (int)$receipt['project_id'] : null, $uid, $receipt['receipt_number']);
        (($res2['reason'] ?? '') === 'already_posted' && (int)$res2['entry_id'] === $eid)
            ? pass('idempotent: second call returns already_posted with the same entry')
            : fail('not idempotent: reason=' . ($res2['reason'] ?? '?'));
        $countNow = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='grn' AND entity_id=$rid AND status='posted'")->fetchColumn();
        ($countNow === 1) ? pass('exactly one posted entry for this receipt') : fail("$countNow posted entries for receipt $rid (want 1)");
    }
} finally {
    $pdo->rollBack();   // leave the database exactly as found
}

$after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='grn' AND status='posted'")->fetchColumn();
($after === $before) ? pass("rolled back cleanly (grn entries: $before → $after)") : fail("LEAKED rows: $before → $after");

// ─────────────────────────────────────────────────────────────────────────
section('3. Ledger still balances after the (rolled-back) test');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr and Assets = Liab + Equity)')
         : fail('ledger out of balance: ' . json_encode($g));
