<?php
/**
 * WHT posting (Phase 2) CLI test.
 *   php tests/test_wht_posting_cli.php
 *
 * Exercises core/wht.php + the postOutflow() withholding split IN-PROCESS, inside
 * a transaction that is ALWAYS rolled back (leaves zero trace in the dev DB).
 * Proves: the 3-line entry (Dr AP gross / Cr Cash net / Cr WHT Payable WHT), the
 * balance moves, reverseOutflow() restoration, the UNCHANGED no-WHT path, and the
 * rate / posted-flag / position helpers.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
require_once "$root/core/wht.php";
global $pdo;
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function approx($a, $b) { return abs((float)$a - (float)$b) <= 0.01; }
function bal(PDO $pdo, int $id) { return (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id = $id")->fetchColumn(); }

try {
    $pdo->beginTransaction();

    // ── Config + fixtures ───────────────────────────────────────────────────
    $whtAcc = whtPayableAccountId($pdo);
    ok($whtAcc !== null, "WHT Payable account configured (id = " . ($whtAcc ?? 'NULL') . ")");
    $cb = cashBankAccounts($pdo);
    ok(count($cb) > 0, "have a cash/bank account");
    $cash = (int)$cb[0]['account_id'];
    $ap   = (int)defaultPayableAccountId($pdo);
    ok($ap > 0, "have an Accounts Payable account");

    // ── Rate maths ──────────────────────────────────────────────────────────
    $r5 = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='wht' AND rate_percentage=5.00 LIMIT 1")->fetchColumn();
    ok(approx(whtRatePercent($pdo, $r5), 5.0), "whtRatePercent(WHT 5%) = 5.0");
    $rVat = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind='vat' LIMIT 1")->fetchColumn();
    ok(approx(whtRatePercent($pdo, $rVat), 0.0), "whtRatePercent(a VAT rate) = 0 (not WHT)");
    ok(approx(computeWht(1000000, 5), 50000), "computeWht(1,000,000 @ 5%) = 50,000");

    // ── 1. WHT split: gross 1,180,000 / WHT 50,000 / net 1,130,000 ──────────
    $cash0 = bal($pdo, $cash); $wht0 = bal($pdo, $whtAcc);
    $txn = postOutflow($pdo, 'received_invoice_payment', $cash, $ap, 1180000, date('Y-m-d'), '__wht_test', 'WHT split test', null, 50000, $whtAcc);
    ok($txn > 0, "postOutflow returned a transaction id");
    $lines = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id = $txn")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($lines) === 3, "3-line journal posted");
    $dr = array_values(array_filter($lines, fn($l) => $l['type'] === 'debit'));
    $cr = array_values(array_filter($lines, fn($l) => $l['type'] === 'credit'));
    ok(count($dr) === 1 && (int)$dr[0]['account_id'] === $ap && approx($dr[0]['amount'], 1180000), "Dr Accounts Payable 1,180,000 (gross)");
    $crCash = array_values(array_filter($cr, fn($l) => (int)$l['account_id'] === $cash));
    $crWht  = array_values(array_filter($cr, fn($l) => (int)$l['account_id'] === $whtAcc));
    ok($crCash && approx($crCash[0]['amount'], 1130000), "Cr Cash 1,130,000 (net)");
    ok($crWht  && approx($crWht[0]['amount'], 50000),    "Cr WHT Payable 50,000");
    ok(approx(bal($pdo, $cash), $cash0 - 1130000), "cash balance reduced by NET 1,130,000");
    ok(approx(bal($pdo, $whtAcc), $wht0 + 50000),  "WHT Payable balance increased by 50,000");

    // ── 2. reverseOutflow restores everything ───────────────────────────────
    reverseOutflow($pdo, $txn);
    ok(approx(bal($pdo, $cash), $cash0), "reverse restored cash balance");
    ok(approx(bal($pdo, $whtAcc), $wht0), "reverse restored WHT Payable balance");
    ok((int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id = $txn")->fetchColumn() === 0, "reverse deleted the ledger lines");

    // ── 3. No-WHT path unchanged: plain 2-line, cash out in full ────────────
    $cash1 = bal($pdo, $cash);
    $txn2 = postOutflow($pdo, 'supplier_payment', $cash, $ap, 200000, date('Y-m-d'), '__wht_test2', 'no-WHT test');
    $l2 = $pdo->query("SELECT type FROM books_transactions WHERE transaction_id = $txn2")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($l2) === 2, "no-WHT payment still posts a plain 2-line entry");
    ok(approx(bal($pdo, $cash), $cash1 - 200000), "no-WHT: cash reduced by the full amount");
    reverseOutflow($pdo, $txn2);
    ok(approx(bal($pdo, $cash), $cash1), "no-WHT: reverse restored cash");

    // ── 4. Posted flag + drift-proof position ───────────────────────────────
    $invId = (int)$pdo->query("SELECT id FROM supplier_invoices WHERE status <> 'deleted' LIMIT 1")->fetchColumn();
    if ($invId) {
        $p0 = whtPosition($pdo)['payable'];
        markWhtPosted($pdo, 'supplier_invoices', $invId, 12345.67);
        ok(approx($pdo->query("SELECT wht_posted FROM supplier_invoices WHERE id = $invId")->fetchColumn(), 12345.67), "markWhtPosted stamped the flag");
        ok(approx(whtPosition($pdo)['payable'], $p0 + 12345.67), "whtPosition picked up the posted amount");
        clearWhtPosted($pdo, 'supplier_invoices', $invId);
        ok($pdo->query("SELECT wht_posted FROM supplier_invoices WHERE id = $invId")->fetchColumn() === null, "clearWhtPosted reset the flag");
    } else {
        ok(true, "no supplier_invoice present to test posted-flag (skipped)");
    }

} catch (Throwable $e) {
    ok(false, "exception: " . $e->getMessage());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();   // leave zero trace in the dev DB
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail === 0 ? 0 : 1);
