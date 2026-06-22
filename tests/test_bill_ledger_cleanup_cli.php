<?php
/**
 * HIGH #2 — legacy Bill/ledger cleanup — verification CLI test.
 *   php tests/test_bill_ledger_cleanup_cli.php
 *
 * Asserts the END STATE the cleanup migration guarantees (the migration must have
 * run first, via the runner). Read-only — no fixtures, no writes:
 *   1. No POSTED journal entry has < 2 legs (malformed entries were voided).
 *   2. The whole ledger balances (Σ Dr = Σ Cr).
 *   3. Every fully-paid supplier Bill that HAS a posted accrual and whose payments
 *      debited AP nets to zero on AP (accrual credit == payment debit).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/gl_accounts.php";
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

$ap = apAccountId($pdo);
$ap ? pass("apAccountId → #$ap") : fail('AP account missing');

section('1. No POSTED journal entry has < 2 legs (malformed voided)');
$bad = (int)$pdo->query("
    SELECT COUNT(*) FROM (
      SELECT je.entry_id
        FROM journal_entries je
        LEFT JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
       WHERE je.status='posted'
       GROUP BY je.entry_id
      HAVING COUNT(jei.item_id) < 2
    ) x")->fetchColumn();
($bad === 0) ? pass('no malformed posted entries remain') : fail("$bad posted entries still have < 2 legs");

section('2. Whole ledger balances (Σ Dr = Σ Cr)');
$r = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) dr,
           COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
      FROM journal_entry_items jei
      JOIN journal_entries je ON je.entry_id = jei.entry_id
     WHERE je.status='posted'")->fetch(PDO::FETCH_ASSOC);
$diff = round((float)$r['dr'] - (float)$r['cr'], 2);
(abs($diff) < 0.01) ? pass("balanced (Σ Dr " . money($r['dr']) . " = Σ Cr " . money($r['cr']) . ")") : fail("OUT by $diff");

section('3. Fully-paid Bills with an accrual + AP-debiting payments net to zero on AP');
$bills = $pdo->query("
    SELECT id, invoice_ref, payment_transaction_id
      FROM supplier_invoices
     WHERE invoice_type='supplier' AND status='paid'")->fetchAll(PDO::FETCH_ASSOC);
$checked = 0; $offenders = 0;
foreach ($bills as $b) {
    $id = (int)$b['id'];
    $apCr = (float)$pdo->query("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je
              JOIN journal_entry_items jei ON jei.entry_id=je.entry_id
              WHERE je.entity_type='supplier_invoice' AND je.entity_id=$id AND je.status='posted'
                AND jei.type='credit' AND jei.account_id=$ap")->fetchColumn();
    if ($apCr <= 0.01) continue;   // no supplier_invoice accrual (e.g. GRN-covered) → out of scope here
    $legacyTxn = (int)($b['payment_transaction_id'] ?: 0);
    $apDr = (float)$pdo->query("SELECT COALESCE(SUM(bt.amount),0) FROM books_transactions bt
              WHERE bt.type='debit' AND bt.account_id=$ap
                AND ( bt.transaction_id IN (SELECT journal_txn_id FROM supplier_invoice_payments WHERE invoice_id=$id)
                      OR bt.transaction_id=$legacyTxn )")->fetchColumn();
    if ($apDr <= 0.01) continue;   // payment didn't debit AP → out of scope
    $checked++;
    if (abs($apCr - $apDr) >= 0.01) { $offenders++; echo "     #{$b['invoice_ref']}: accrual Cr " . money($apCr) . " vs payment Dr " . money($apDr) . " — net " . money($apCr - $apDr) . "\n"; }
}
($checked > 0) ? pass("checked $checked fully-paid Bill(s) with accrual + AP payment") : pass('no in-scope paid Bills to check (vacuously ok)');
($offenders === 0) ? pass('every in-scope paid Bill nets AP to zero') : fail("$offenders Bill(s) do not net to zero on AP");
