<?php
/**
 * OUT-8 — Purchase return → GL posting — CLI test
 *   php tests/test_purchase_return_posting_cli.php
 *
 * Guards core/purchase_posting.php::postPurchaseReturn(): approving a purchase
 * return posts the balanced contra of the GRN — Dr Accounts Payable / Cr Inventory
 * for the goods value — idempotently, against the SAME AP account the GRN credits,
 * and reverses cleanly on reject/cancel. Runs the real helper against a real return
 * inside a ROLLED-BACK transaction, so the database is left untouched.
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
section('1. Control accounts resolve, and return-debit AP == GRN/payment AP');
$inv = inventoryAccountId($pdo);
$ap  = apAccountId($pdo);
$payAp = defaultPayableAccountId($pdo);
$inv ? pass("inventoryAccountId → #$inv") : fail('inventoryAccountId returned null (Inventory account missing)');
$ap  ? pass("apAccountId → #$ap")        : fail('apAccountId returned null (AP account missing)');
($ap && $payAp && (int)$ap === (int)$payAp)
    ? pass("AP debited by return (#$ap) == AP credited by GRN / debited by payments (#$payAp) — nets")
    : fail("AP mismatch: return debits #$ap but payments use #" . ($payAp ?? 'NULL'));

// ─────────────────────────────────────────────────────────────────────────
section('2. postPurchaseReturn posts a balanced Dr AP / Cr Inventory (rolled back)');
$ret = $pdo->query("
    SELECT pr.purchase_return_id, pr.return_number, pr.return_date, pr.total_amount,
           COALESCE(SUM(pri.quantity * pri.unit_price), 0) val
      FROM purchase_returns pr
      JOIN purchase_return_items pri ON pri.purchase_return_id = pr.purchase_return_id
  GROUP BY pr.purchase_return_id
    HAVING val > 0 OR pr.total_amount > 0
  ORDER BY pr.purchase_return_id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$ret) {
    fail('no purchase return with items/value found — cannot run the functional test');
    return;
}
$rid = (int)$ret['purchase_return_id'];
$val = round((float)$ret['total_amount'] > 0 ? (float)$ret['total_amount'] : (float)$ret['val'], 2);
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
echo "   using return #$rid {$ret['return_number']}  value=" . money($val) . "\n";

$before = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='purchase_return' AND status='posted'")->fetchColumn();

$pdo->beginTransaction();
try {
    $res = postPurchaseReturn($pdo, $rid, $uid);
    (!empty($res['posted']) && !empty($res['entry_id']))
        ? pass("posted (entry #{$res['entry_id']})")
        : fail("did not post: reason=" . ($res['reason'] ?? '?'));

    if (!empty($res['entry_id'])) {
        $eid = (int)$res['entry_id'];
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid ORDER BY type")->fetchAll(PDO::FETCH_ASSOC);
        $hdr = $pdo->query("SELECT entity_type, status FROM journal_entries WHERE entry_id=$eid")->fetch(PDO::FETCH_ASSOC);

        (count($lines) === 2) ? pass('entry has exactly 2 lines') : fail('entry has ' . count($lines) . ' lines (want 2)');
        $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
        foreach ($lines as $l) { if ($l['type']==='debit'){$dr=(float)$l['amount'];$drAcc=(int)$l['account_id'];} else {$cr=(float)$l['amount'];$crAcc=(int)$l['account_id'];} }
        (abs($dr - $cr) < 0.01) ? pass("balanced (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("unbalanced Dr $dr vs Cr $cr");
        (abs($dr - $val) < 0.01) ? pass('amount equals goods value') : fail("amount $dr != goods value $val");
        ($drAcc === (int)$ap)  ? pass('debit is the Accounts Payable account') : fail("debit acct #$drAcc != AP #$ap");
        ($crAcc === (int)$inv) ? pass('credit is the Inventory account')        : fail("credit acct #$crAcc != Inventory #$inv");
        (($hdr['entity_type'] ?? '') === 'purchase_return') ? pass("entity_type='purchase_return'") : fail("entity_type=" . ($hdr['entity_type'] ?? 'null'));

        // Idempotency within the same tx
        $res2 = postPurchaseReturn($pdo, $rid, $uid);
        (($res2['reason'] ?? '') === 'already_posted' && (int)$res2['entry_id'] === $eid)
            ? pass('idempotent: second call returns already_posted with the same entry')
            : fail('not idempotent: reason=' . ($res2['reason'] ?? '?'));

        // Reversal posts the contra (Dr Inventory / Cr AP) under *_void
        $rev = reversePurchaseReturn($pdo, $rid, $uid);
        (!empty($rev['reversed']) && !empty($rev['entry_id']))
            ? pass("reversal posts a contra entry (#{$rev['entry_id']})")
            : fail('reversal did not post: reason=' . ($rev['reason'] ?? '?'));
        if (!empty($rev['entry_id'])) {
            $rl = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=" . (int)$rev['entry_id'])->fetchAll(PDO::FETCH_ASSOC);
            $revOk = false;
            foreach ($rl as $l) { if ((int)$l['account_id'] === (int)$inv && $l['type'] === 'debit' && abs((float)$l['amount'] - $val) < 0.01) $revOk = true; }
            $revOk ? pass('reversal re-debits Inventory (puts the goods back on the books)') : fail('reversal contra not as expected');
        }
    }
} finally {
    $pdo->rollBack();   // leave the database exactly as found
}

$after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='purchase_return' AND status='posted'")->fetchColumn();
($after === $before) ? pass("rolled back cleanly (purchase_return entries: $before → $after)") : fail("LEAKED rows: $before → $after");

// ─────────────────────────────────────────────────────────────────────────
section('3. Ledger still balances after the (rolled-back) test');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr and Assets = Liab + Equity)')
         : fail('ledger out of balance: ' . json_encode($g));
