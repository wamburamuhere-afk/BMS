<?php
/**
 * PHASE 1 — Bill posting splits Input VAT out of the cost — CLI test.
 *   php tests/test_bill_vat_split_cli.php
 *
 * Verifies the Bill accrual (at 'approved') posts the correct, balanced entry:
 *   VAT goods   → Dr Cost(net) + Dr Input VAT(tax) / Cr AP(gross)   (3 lines)
 *   no-VAT      → Dr Cost(gross) / Cr AP(gross)                     (2 lines, no regression)
 *   VAT service → Dr COGS(net) + Dr Input VAT(tax) / Cr AP(gross)   (3 lines)
 * and that Input VAT now reaches the GENERAL LEDGER (journal_entry_items).
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

// Pull the legs of an entry as [account_id => ['debit'=>x,'credit'=>y]].
function legsOf(PDO $pdo, int $entryId): array {
    $o = [];
    foreach ($pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$entryId") as $l) {
        $o[(int)$l['account_id']][$l['type']] = ($o[(int)$l['account_id']][$l['type']] ?? 0) + (float)$l['amount'];
    }
    return $o;
}

section('0. Setup — control accounts');
$inv = inventoryAccountId($pdo);
$cogs= cogsAccountId($pdo);
$ap  = apAccountId($pdo);
$vat = inputVatAccountId($pdo);
$inv  ? pass("Inventory → #$inv")   : fail('Inventory missing');
$cogs ? pass("COGS → #$cogs")       : fail('COGS missing');
$ap   ? pass("AP → #$ap")           : fail('AP missing');
$vat  ? pass("Input VAT → #$vat")   : fail('Input VAT account missing');

$sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

if (!$inv || !$cogs || !$ap || !$vat || !$sid) { fail('prerequisites missing'); } else {
$pdo->beginTransaction();
try {
    // ── 1. VAT goods bill: net 250,000 + VAT 45,000 = gross 295,000 ──────────
    section('1. VAT goods Bill → Dr Inventory(net) + Dr Input VAT / Cr AP(gross)');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-VAT-G', '2026-06-01','2026-06-01', 295000, 250000, 45000, 'approved', ?)")
        ->execute([$sid, $uid]);
    $g = (int)$pdo->lastInsertId();
    $res = postGoodsInvoiceAccrual($pdo, $g, $uid);
    if (!empty($res['entry_id'])) {
        $L = legsOf($pdo, (int)$res['entry_id']);
        (abs(($L[$inv]['debit'] ?? 0) - 250000) < 0.01) ? pass('Inventory debited NET 250,000') : fail('Inventory = ' . money($L[$inv]['debit'] ?? 0) . ' (expected 250,000 net)');
        (abs(($L[$vat]['debit'] ?? 0) - 45000)  < 0.01) ? pass('Input VAT debited 45,000')      : fail('Input VAT = ' . money($L[$vat]['debit'] ?? 0) . ' (expected 45,000)');
        (abs(($L[$ap]['credit'] ?? 0) - 295000) < 0.01) ? pass('AP credited GROSS 295,000')      : fail('AP = ' . money($L[$ap]['credit'] ?? 0) . ' (expected 295,000)');
        $dr = array_sum(array_column($L, 'debit')); $cr = array_sum(array_column($L, 'credit'));
        (abs($dr - $cr) < 0.01) ? pass("balanced (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("unbalanced Dr $dr / Cr $cr");
    } else fail('did not post: ' . json_encode($res));

    // ── 2. No-VAT goods bill: 30,000 → 2-line, no regression ─────────────────
    section('2. No-VAT goods Bill → 2-line Dr Inventory / Cr AP (no regression)');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-VAT-NG', '2026-06-02','2026-06-02', 30000, 30000, 0, 'approved', ?)")
        ->execute([$sid, $uid]);
    $ng = (int)$pdo->lastInsertId();
    $res2 = postGoodsInvoiceAccrual($pdo, $ng, $uid);
    if (!empty($res2['entry_id'])) {
        $L = legsOf($pdo, (int)$res2['entry_id']);
        (abs(($L[$inv]['debit'] ?? 0) - 30000) < 0.01) ? pass('Inventory debited 30,000 (full, no VAT)') : fail('Inventory = ' . money($L[$inv]['debit'] ?? 0));
        (!isset($L[$vat])) ? pass('no Input VAT line (correct for a no-VAT bill)') : fail('unexpected Input VAT line on a no-VAT bill');
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id={$res2['entry_id']}")->fetchColumn();
        ($cnt === 2) ? pass('exactly 2 lines') : fail("expected 2 lines, got $cnt");
    } else fail('did not post: ' . json_encode($res2));

    // ── 3. VAT sub-contractor bill: net 100,000 + VAT 18,000 = 118,000 ───────
    section('3. VAT service Bill → Dr COGS(net) + Dr Input VAT / Cr AP(gross)');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('sub_contractor', ?, 'TEST-VAT-S', '2026-06-03','2026-06-03', 118000, 100000, 18000, 'approved', ?)")
        ->execute([$sid, $uid]);
    $s = (int)$pdo->lastInsertId();
    $res3 = postSubcontractorAccrual($pdo, $s, $uid);
    if (!empty($res3['entry_id'])) {
        $L = legsOf($pdo, (int)$res3['entry_id']);
        (abs(($L[$cogs]['debit'] ?? 0) - 100000) < 0.01) ? pass('COGS debited NET 100,000') : fail('COGS = ' . money($L[$cogs]['debit'] ?? 0));
        (abs(($L[$vat]['debit'] ?? 0) - 18000)   < 0.01) ? pass('Input VAT debited 18,000')  : fail('Input VAT = ' . money($L[$vat]['debit'] ?? 0));
        (abs(($L[$ap]['credit'] ?? 0) - 118000)  < 0.01) ? pass('AP credited GROSS 118,000')  : fail('AP = ' . money($L[$ap]['credit'] ?? 0));
    } else fail('did not post: ' . json_encode($res3));

    // ── 4. Input VAT now has GL lines, and ledger balances ───────────────────
    section('4. Input VAT reaches the GL + ledger balances');
    $vatLines = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE account_id=$vat")->fetchColumn();
    ($vatLines >= 2) ? pass("Input VAT account has $vatLines GL line(s) (was 0 before this fix)") : fail('Input VAT still absent from the GL');
    $bal = assertLedgerBalanced($pdo);
    ($bal['ledger_balanced']) ? pass("Σ Dr = Σ Cr (diff " . money($bal['dr_cr_difference']) . ")") : fail('ledger out of balance');

} finally {
    $pdo->rollBack();
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE invoice_ref LIKE 'TEST-VAT-%'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no fixtures persisted') : fail("LEAKED $leftover rows");
}
