<?php
/**
 * PHASE 2 — buried-VAT remediation — CLI test.
 *   php tests/test_bill_vat_remediation_cli.php
 *
 * Local data has no VAT-buried entries, so we SEED an old-style gross accrual
 * (Dr Inventory gross / Cr AP gross, no Input VAT line) for a VAT bill, then run
 * remediateBuriedVatForEntry() and verify it moves the VAT out:
 *   - posts Dr Input VAT / Cr Inventory for the tax
 *   - net Inventory = net, Input VAT = tax, AP unchanged
 *   - idempotent (second run = already_remediated, no new entry)
 *   - skips a no-VAT entry and an already-split entry
 *   - ledger stays balanced
 *
 * All inside a single ROLLED-BACK transaction; the DB is unchanged.
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

// net balance of an account across only the given bill (accrual + remediation, by entity/parent)
function billAcctNet(PDO $pdo, int $acc, int $billId): float {
    return (float)$pdo->query("
        SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
          FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id
         WHERE jei.account_id=$acc AND je.status='posted'
           AND ((je.entity_type IN ('supplier_invoice','bill_vat_remediation') AND je.entity_id=$billId)
                OR (je.parent_entity_type='supplier_invoice' AND je.parent_entity_id=$billId))")->fetchColumn();
}

$inv = inventoryAccountId($pdo);
$ap  = apAccountId($pdo);
$vat = inputVatAccountId($pdo);
$sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
($inv && $ap && $vat && $sid) ? pass("accounts resolve (Inv #$inv, AP #$ap, VAT #$vat)") : fail('prerequisites missing');

if (!($inv && $ap && $vat && $sid)) { fail('cannot run'); } else {
$pdo->beginTransaction();
try {
    // Seed a VAT bill: net 200,000 + VAT 36,000 = gross 236,000
    section('1. Seed an OLD gross accrual (VAT buried) and remediate it');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-REM-A', '2026-05-01','2026-05-01', 236000, 200000, 36000, 'approved', ?)")
        ->execute([$sid, $uid]);
    $bill = (int)$pdo->lastInsertId();
    // Old-style gross entry (simulating pre-fix posting): Dr Inventory 236,000 / Cr AP 236,000
    $oldEntry = postLedgerEntry($pdo, "OLD gross accrual (seed)", [
        ['account_id' => $inv, 'type' => 'debit',  'amount' => 236000, 'description' => 'gross (VAT buried)'],
        ['account_id' => $ap,  'type' => 'credit', 'amount' => 236000, 'description' => 'AP gross'],
    ], null, $bill, 'supplier_invoice', '2026-05-01', $uid);
    pass("seeded old gross entry #$oldEntry (Inv 236,000 incl 36,000 VAT)");

    $res = remediateBuriedVatForEntry($pdo, $oldEntry, $uid);
    (($res['reason'] ?? '') === 'remediated' && !empty($res['entry_id']))
        ? pass("remediated → new reclass entry #{$res['entry_id']}")
        : fail('remediation did not run: ' . json_encode($res));

    section('2. After remediation: Inventory=net, Input VAT=tax, AP unchanged');
    $invNet = billAcctNet($pdo, $inv, $bill);
    $vatNet = billAcctNet($pdo, $vat, $bill);
    $apNet  = -billAcctNet($pdo, $ap, $bill); // AP is credit-normal; net credit
    (abs($invNet - 200000) < 0.01) ? pass("Inventory now NET 200,000 (was 236,000)") : fail("Inventory = " . money($invNet));
    (abs($vatNet - 36000)  < 0.01) ? pass("Input VAT now 36,000")                    : fail("Input VAT = " . money($vatNet));
    (abs($apNet - 236000)  < 0.01) ? pass("AP unchanged at GROSS 236,000")           : fail("AP = " . money($apNet));

    section('3. Idempotent — second run posts nothing new');
    $res2 = remediateBuriedVatForEntry($pdo, $oldEntry, $uid);
    (($res2['reason'] ?? '') === 'already_remediated' && empty($res2['entry_id']))
        ? pass('second run → already_remediated, no new entry')
        : fail('not idempotent: ' . json_encode($res2));

    section('4. Skips a no-VAT entry and an already-split entry');
    // no-VAT bill
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-REM-NV', '2026-05-02','2026-05-02', 50000, 50000, 0, 'approved', ?)")->execute([$sid, $uid]);
    $billNV = (int)$pdo->lastInsertId();
    $eNV = postLedgerEntry($pdo, "no-vat seed", [
        ['account_id'=>$inv,'type'=>'debit','amount'=>50000,'description'=>'x'],
        ['account_id'=>$ap,'type'=>'credit','amount'=>50000,'description'=>'x'],
    ], null, $billNV, 'supplier_invoice', '2026-05-02', $uid);
    (( remediateBuriedVatForEntry($pdo, $eNV, $uid)['reason'] ?? '') === 'no_vat') ? pass('no-VAT entry skipped (no_vat)') : fail('no-VAT not skipped');

    // already-split entry (post via the new code path)
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, amount, subtotal, tax_amount, status, recorded_by)
                   VALUES ('supplier', ?, 'TEST-REM-SP', '2026-05-03','2026-05-03', 118000, 100000, 18000, 'approved', ?)")->execute([$sid, $uid]);
    $billSP = (int)$pdo->lastInsertId();
    $rsp = postGoodsInvoiceAccrual($pdo, $billSP, $uid);  // already splits VAT
    $eSP = (int)$rsp['entry_id'];
    (( remediateBuriedVatForEntry($pdo, $eSP, $uid)['reason'] ?? '') === 'already_split') ? pass('already-split entry skipped (already_split)') : fail('already-split not skipped');

    section('5. Ledger balances');
    $bal = assertLedgerBalanced($pdo);
    ($bal['ledger_balanced']) ? pass("Σ Dr = Σ Cr (diff " . money($bal['dr_cr_difference']) . ")") : fail('ledger out of balance');

} finally {
    $pdo->rollBack();
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE invoice_ref LIKE 'TEST-REM-%'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no fixtures persisted') : fail("LEAKED $leftover rows");
}
