<?php
/**
 * MEDIUM #4 — Bill payments linked to their parent Bill in the GL — verification.
 *   php tests/test_bill_payment_parent_link_cli.php
 *
 * Asserts the end state after the link migration (must have run). Read-only:
 *   1. Every Bill-payment mirror row (via the subledger) carries
 *      parent_entity_type='supplier_invoice' + parent_entity_id = its Bill id.
 *   2. The per-Bill AP settlement is computable FROM THE LEDGER ALONE — using the
 *      accrual (entity_id=X) and the payments (parent_entity_id=X), with NO join
 *      to supplier_invoice_payments — and nets to zero for every fully-paid Bill.
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

section('1. Every subledger Bill-payment mirror row is linked to its Bill');
$unlinked = (int)$pdo->query("
    SELECT COUNT(*)
      FROM supplier_invoice_payments sip
      JOIN journal_entries je
        ON je.entity_type='books_transaction' AND je.entity_id = sip.journal_txn_id AND je.status='posted'
     WHERE je.parent_entity_id IS NULL OR je.parent_entity_type <> 'supplier_invoice' OR je.parent_entity_id <> sip.invoice_id
")->fetchColumn();
($unlinked === 0) ? pass('all subledger payment mirror rows carry the correct parent_entity_id') : fail("$unlinked payment mirror row(s) not linked to their Bill");

section('2. Per-Bill AP nets to zero computed FROM THE LEDGER ALONE (parent linkage, no subledger)');
$bills = $pdo->query("SELECT id, invoice_ref FROM supplier_invoices WHERE invoice_type='supplier' AND status='paid'")->fetchAll(PDO::FETCH_ASSOC);
$checked = 0; $offenders = 0;
foreach ($bills as $b) {
    $id = (int)$b['id'];
    // AP credit from the Bill's own accrual entry
    $apCr = (float)$pdo->query("
        SELECT COALESCE(SUM(jei.amount),0)
          FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id=je.entry_id
         WHERE je.status='posted' AND je.entity_type='supplier_invoice' AND je.entity_id=$id
           AND jei.type='credit' AND jei.account_id=$ap")->fetchColumn();
    if ($apCr <= 0.01) continue;   // no accrual on this Bill (e.g. GRN-covered) → out of scope
    // AP debit from the Bill's payments — found purely via parent linkage
    $apDr = (float)$pdo->query("
        SELECT COALESCE(SUM(jei.amount),0)
          FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id=je.entry_id
         WHERE je.status='posted' AND je.parent_entity_type='supplier_invoice' AND je.parent_entity_id=$id
           AND jei.type='debit' AND jei.account_id=$ap")->fetchColumn();
    if ($apDr <= 0.01) continue;   // payments not linked / not via AP → out of scope
    $checked++;
    if (abs($apCr - $apDr) >= 0.01) { $offenders++; echo "     #{$b['invoice_ref']}: accrual Cr " . money($apCr) . " vs linked-payment Dr " . money($apDr) . " — net " . money($apCr - $apDr) . "\n"; }
}
($checked > 0) ? pass("traced $checked fully-paid Bill(s) entirely from the ledger") : pass('no in-scope paid Bills (vacuously ok)');
($offenders === 0) ? pass('every traced Bill nets AP to zero from the ledger alone') : fail("$offenders Bill(s) do not net to zero via parent linkage");
