<?php
/**
 * OUT-7 — Goods payable posting — CLI test
 *   php tests/test_grn_posting_cli.php
 *
 * Policy (money.md OUT-7, updated): GRN approval no longer posts to the GL — it
 * only ever recognised goods received before the supplier invoice existed. The
 * payable now posts at INVOICE-APPROVAL time via postGoodsInvoiceAccrual(), with
 * an amount-based cutover guard that nets off any value already posted via an
 * old-rule GRN for the same PO (and self-heals a PO whose GRN(s) only partially
 * posted — the exact split found live on PO #52: one receipt posted under the
 * old rule, a second receipt never posted at all).
 *
 * All scenarios run inside a single ROLLED-BACK transaction; the database is
 * left exactly as found.
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
section('1. Control accounts resolve, and AP credited by accrual == AP debited by payment');
$inv   = inventoryAccountId($pdo);
$ap    = apAccountId($pdo);
$payAp = defaultPayableAccountId($pdo);
$inv ? pass("inventoryAccountId → #$inv") : fail('inventoryAccountId returned null (Inventory account missing)');
$ap  ? pass("apAccountId → #$ap")        : fail('apAccountId returned null (AP account missing)');
($ap && $payAp && (int)$ap === (int)$payAp)
    ? pass("AP credited by accrual (#$ap) == AP debited by payments (#$payAp) — nets correctly")
    : fail("AP mismatch: accrual credits #$ap but payments debit #" . ($payAp ?? 'NULL') . " — AP would never net");

// ─────────────────────────────────────────────────────────────────────────
section('2. GRN approval no longer posts to the GL (policy moved to invoice time)');
$src = file_get_contents("$root/api/approve_grn.php");
(strpos($src, 'postGrnReceipt(') === false) ? pass('approve_grn.php no longer calls postGrnReceipt()') : fail('approve_grn.php still calls postGrnReceipt()');
(strpos($src, "require_once __DIR__ . '/../core/purchase_posting.php'") === false) ? pass('unused purchase_posting.php require removed') : fail('stale require still present');

$supplierId = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid        = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

if (!$supplierId || !$inv || !$ap) {
    fail('no active supplier or control accounts found — cannot run the functional scenarios');
} else {

$pdo->beginTransaction();
try {
    // ── Fixture: a PO with two receipts — one already posted under the OLD GRN
    // rule, one never posted at all. Mirrors the real PO #52 split found live.
    $pdo->exec("INSERT INTO purchase_orders (order_number, supplier_id, order_date, status)
                VALUES ('TEST-PO-OUT7', $supplierId, '2026-05-01', 'approved')");
    $poId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO purchase_receipts (purchase_order_id, supplier_id, receipt_date, status)
                VALUES ($poId, $supplierId, '2026-05-02', 'approved')");
    $postedReceiptId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO purchase_receipts (purchase_order_id, supplier_id, receipt_date, status)
                VALUES ($poId, $supplierId, '2026-05-03', 'completed')");
    $unpostedReceiptId = (int)$pdo->lastInsertId();

    // Simulate the OLD rule having already posted the first receipt's GRN.
    $grnVal   = 100000.00;
    $grnEntry = postLedgerEntry($pdo, 'TEST seed — old-rule GRN posting', [
        ['account_id' => $inv, 'type' => 'debit',  'amount' => $grnVal, 'description' => 'seed'],
        ['account_id' => $ap,  'type' => 'credit', 'amount' => $grnVal, 'description' => 'seed'],
    ], null, $postedReceiptId, 'grn', '2026-05-02', $uid);
    $grnEntry ? pass("seeded an old-rule posted GRN entry for receipt #$postedReceiptId") : fail('seed GRN entry failed');

    // ── 3. Fully covered ───────────────────────────────────────────────────
    section('3. Guard — invoice exactly matches the already-posted GRN: fully covered, nothing new posted');
    $pdo->exec("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, status, recorded_by)
                VALUES ('supplier', $supplierId, 'TEST-INV-OUT7-A', '2026-05-05', '2026-05-05', $poId, $grnVal, 'approved', $uid)");
    $invIdA = (int)$pdo->lastInsertId();
    $resA   = postGoodsInvoiceAccrual($pdo, $invIdA, $uid);
    ($resA['posted'] === true && ($resA['reason'] ?? '') === 'covered_by_grn')
        ? pass('fully covered by the already-posted GRN — skipped, no double-post')
        : fail('expected covered_by_grn, got: ' . json_encode($resA));
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='supplier_invoice' AND entity_id=$invIdA")->fetchColumn();
    ($cnt === 0) ? pass('no new journal entry created for the fully-covered invoice') : fail("unexpectedly created $cnt entries");

    // ── 4. Self-heals the never-posted receipt ───────────────────────────────
    section('4. Guard — invoice covers BOTH receipts (one posted, one never posted): posts only the shortfall');
    $neverPostedVal = 45000.00;
    $fullVal        = $grnVal + $neverPostedVal;
    $pdo->exec("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, status, recorded_by)
                VALUES ('supplier', $supplierId, 'TEST-INV-OUT7-B', '2026-05-06', '2026-05-06', $poId, $fullVal, 'approved', $uid)");
    $invIdB = (int)$pdo->lastInsertId();
    $resB   = postGoodsInvoiceAccrual($pdo, $invIdB, $uid);
    (($resB['reason'] ?? '') === 'posted_partial_remainder' && !empty($resB['entry_id']))
        ? pass('posted only the shortfall (entry #' . $resB['entry_id'] . ')')
        : fail('expected posted_partial_remainder, got: ' . json_encode($resB));
    if (!empty($resB['entry_id'])) {
        $lines = $pdo->query("SELECT type, amount FROM journal_entry_items WHERE entry_id={$resB['entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { if ($l['type'] === 'debit') $dr += (float)$l['amount']; else $cr += (float)$l['amount']; }
        (abs($dr - $cr) < 0.01) ? pass('balanced (Dr ' . money($dr) . ' = Cr ' . money($cr) . ')') : fail("unbalanced Dr $dr / Cr $cr");
        (abs($dr - $neverPostedVal) < 0.01)
            ? pass("shortfall amount equals the never-posted receipt's value (" . money($neverPostedVal) . ") — gap self-healed")
            : fail("shortfall $dr != " . money($neverPostedVal) . " — the previously-missed receipt was not captured correctly");
    }

    // ── 5. Clean invoice, no PO at all ───────────────────────────────────────
    section('5. A manually-entered invoice with no PO posts its full amount — guard not involved');
    $cleanVal = 77000.00;
    $pdo->exec("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, po_id, amount, status, recorded_by)
                VALUES ('supplier', $supplierId, 'TEST-INV-OUT7-C', '2026-05-07', '2026-05-07', NULL, $cleanVal, 'approved', $uid)");
    $invIdC = (int)$pdo->lastInsertId();
    $resC   = postGoodsInvoiceAccrual($pdo, $invIdC, $uid);
    (($resC['reason'] ?? '') === 'posted' && !empty($resC['entry_id']))
        ? pass('manual invoice with no PO posts cleanly')
        : fail('expected posted, got: ' . json_encode($resC));

    // ── 6. Idempotent ─────────────────────────────────────────────────────
    section('6. Idempotent — re-approving the same invoice never double-posts');
    $resC2 = postGoodsInvoiceAccrual($pdo, $invIdC, $uid);
    (($resC2['reason'] ?? '') === 'already_posted' && (int)$resC2['entry_id'] === (int)$resC['entry_id'])
        ? pass('second call on the same invoice → already_posted, same entry')
        : fail('not idempotent: ' . json_encode($resC2));

    // ── 7. Reversible ─────────────────────────────────────────────────────
    section('7. reverseGoodsInvoiceAccrual reverses a posted accrual, and no-ops on a skipped one');
    $rev = reverseGoodsInvoiceAccrual($pdo, $invIdC, $uid);
    (!empty($rev['reversed'])) ? pass('reversed the clean invoice\'s accrual') : fail('reverse failed: ' . json_encode($rev));
    $revSkip = reverseGoodsInvoiceAccrual($pdo, $invIdA, $uid);
    (empty($revSkip['reversed']) && ($revSkip['reason'] ?? '') === 'no_accrual')
        ? pass('no-ops on the covered_by_grn invoice (nothing was posted to reverse)')
        : fail('expected no_accrual no-op, got: ' . json_encode($revSkip));

} finally {
    $pdo->rollBack();
}

$leftover = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE order_number='TEST-PO-OUT7'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no test fixtures persisted') : fail("LEAKED $leftover test PO rows");

}

// ─────────────────────────────────────────────────────────────────────────
section('8. Ledger still balances after the (rolled-back) test');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr and Assets = Liab + Equity)')
         : fail('ledger out of balance: ' . json_encode($g));
