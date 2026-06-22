<?php
/**
 * HIGH #1 — Bill cost-account selection — CLI test
 *   php tests/test_bill_cost_account_cli.php
 *
 * Verifies postGoodsInvoiceAccrual() debits the Bill's chosen cost_account_id
 * when set (an Expense / COGS / Inventory leaf), and falls back to the canonical
 * Inventory account when it is not set or invalid (zero regression). The credit
 * leg stays on AP, every entry balances, and the ledger stays balanced overall.
 *
 * All scenarios run inside a single ROLLED-BACK transaction; the DB is unchanged.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/purchase_posting.php";
require_once "$root/core/gl_accounts.php";
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

section('0. Setup — control accounts, schema column, a distinct expense leaf');
$inv = inventoryAccountId($pdo);
$ap  = apAccountId($pdo);
$inv ? pass("inventoryAccountId → #$inv") : fail('inventory account missing');
$ap  ? pass("apAccountId → #$ap")        : fail('AP account missing');

$col = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'cost_account_id'")->fetchColumn();
$col ? pass('supplier_invoices.cost_account_id column exists') : fail('cost_account_id column MISSING — run the migration');

$expense = (int)$pdo->query("
    SELECT a.account_id FROM accounts a
    LEFT JOIN account_types t ON t.type_id = a.account_type_id
    WHERE a.status='active' AND a.account_id <> " . (int)$inv . "
      AND (a.account_type='expense' OR t.category IN ('expense','cogs','finance_cost'))
      AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id = a.account_id)
    ORDER BY a.account_code LIMIT 1")->fetchColumn();
$expense ? pass("found an expense leaf account → #$expense") : fail('no expense leaf account found');

$supplierId = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid        = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

if (!$inv || !$ap || !$expense || !$supplierId) {
    fail('missing prerequisites — cannot run scenarios');
} else {
$pdo->beginTransaction();
try {
    // (1) Bill WITH a chosen cost account → debits that account, not Inventory
    section('1. Bill with a chosen cost account posts Dr <chosen> / Cr AP');
    $valA = 50000.00;
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, cost_account_id, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-CA-A', '2026-06-01', '2026-06-01', ?, ?, 'approved', ?)")
        ->execute([$supplierId, $valA, $expense, $uid]);
    $invA = (int)$pdo->lastInsertId();
    $resA = postGoodsInvoiceAccrual($pdo, $invA, $uid);
    (!empty($resA['posted']) && !empty($resA['entry_id'])) ? pass('posted') : fail('did not post: ' . json_encode($resA));
    if (!empty($resA['entry_id'])) {
        $legs = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id={$resA['entry_id']}")->fetchAll(PDO::FETCH_ASSOC);
        $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
        foreach ($legs as $l) { if ($l['type'] === 'debit') { $dr += $l['amount']; $drAcc = (int)$l['account_id']; } else { $cr += $l['amount']; $crAcc = (int)$l['account_id']; } }
        (abs($dr - $cr) < 0.01) ? pass("balanced (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("unbalanced Dr $dr / Cr $cr");
        ($drAcc === (int)$expense) ? pass("debit leg uses the CHOSEN account #$expense (not Inventory)") : fail("debit hit #$drAcc, expected chosen #$expense");
        ($crAcc === (int)$ap) ? pass("credit leg uses AP #$ap") : fail("credit hit #$crAcc, expected AP #$ap");
    }

    // (2) Bill with NO cost account → falls back to Inventory
    section('2. Bill with no cost account falls back to Inventory (no regression)');
    $valB = 30000.00;
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, cost_account_id, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-CA-B', '2026-06-02', '2026-06-02', ?, NULL, 'approved', ?)")
        ->execute([$supplierId, $valB, $uid]);
    $invB = (int)$pdo->lastInsertId();
    $resB = postGoodsInvoiceAccrual($pdo, $invB, $uid);
    if (!empty($resB['entry_id'])) {
        $drAccB = (int)$pdo->query("SELECT account_id FROM journal_entry_items WHERE entry_id={$resB['entry_id']} AND type='debit' LIMIT 1")->fetchColumn();
        ($drAccB === (int)$inv) ? pass("debit leg falls back to Inventory #$inv") : fail("debit hit #$drAccB, expected Inventory #$inv");
    } else fail('did not post: ' . json_encode($resB));

    // (3) Invalid / inactive cost account → defensive fallback to Inventory
    section('3. Invalid cost account falls back to Inventory (defensive)');
    $valC = 12000.00;
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, cost_account_id, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-CA-C', '2026-06-03', '2026-06-03', ?, 999999999, 'approved', ?)")
        ->execute([$supplierId, $valC, $uid]);
    $invC = (int)$pdo->lastInsertId();
    $resC = postGoodsInvoiceAccrual($pdo, $invC, $uid);
    if (!empty($resC['entry_id'])) {
        $drAccC = (int)$pdo->query("SELECT account_id FROM journal_entry_items WHERE entry_id={$resC['entry_id']} AND type='debit' LIMIT 1")->fetchColumn();
        ($drAccC === (int)$inv) ? pass("invalid account ignored, debit falls back to Inventory #$inv") : fail("debit hit #$drAccC, expected Inventory #$inv");
    } else fail('did not post: ' . json_encode($resC));

    // (4) Ledger still balances overall
    section('4. Ledger still balances after the new postings');
    $bal = assertLedgerBalanced($pdo);
    ($bal['ledger_balanced']) ? pass("Σ Dr = Σ Cr (diff " . money($bal['dr_cr_difference']) . ")") : fail('ledger out of balance: ' . json_encode($bal));

} finally {
    $pdo->rollBack();
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE invoice_ref LIKE 'TEST-CA-%'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no fixtures persisted') : fail("LEAKED $leftover rows");
}
