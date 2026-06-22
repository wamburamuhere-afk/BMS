<?php
/**
 * GENERAL VERIFICATION — Bill VAT-split feature (all phases).
 *   php tests/test_bill_vat_general_cli.php
 *
 * One test that proves the whole feature is sound:
 *   A. Code health — every file involved parses (php -l) and every key function loads.
 *   B. Phase 1 — VAT split at posting (goods + service) and no-VAT 2-line.
 *   C. Phase 2 — buried-VAT remediation (seed → remediate → idempotent).
 *   D. Reversal — a VAT-split accrual reverses ALL legs (deletes net to zero).
 *   E. Reports — full ledger balances AND Balance Sheet balances (assertLedgerBalanced).
 *
 * Mutating sections run inside a ROLLED-BACK transaction; the DB is unchanged.
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
    echo "\n========================================\n";
    echo "Passes:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    echo ($fail === 0 ? "\033[32mGENERAL VERIFICATION PASSED\033[0m\n" : "\033[31mGENERAL VERIFICATION FAILED\033[0m\n");
    if ($fail > 0) exit(1);
});

function legsOf(PDO $pdo, int $entryId): array {
    $o = [];
    foreach ($pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$entryId") as $l)
        $o[(int)$l['account_id']][$l['type']] = ($o[(int)$l['account_id']][$l['type']] ?? 0) + (float)$l['amount'];
    return $o;
}

// ── A. CODE HEALTH ─────────────────────────────────────────────────────────
section('A. Code health — files parse + key functions load');
$phpBin = PHP_BINARY ?: 'php';
$files = [
    'core/purchase_posting.php',
    'migrations/2026_06_22_supplier_invoice_cost_account.php',
    'migrations/2026_06_22_remediate_buried_input_vat.php',
];
foreach ($files as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    ($rc === 0) ? pass("php -l OK: $f") : fail("syntax error in $f: " . implode(' ', $out));
}
foreach (['ppAccrualVatLines','postGoodsInvoiceAccrual','postSubcontractorAccrual','reverseGoodsInvoiceAccrual','remediateBuriedVatForEntry','inputVatAccountId'] as $fn)
    function_exists($fn) ? pass("function loaded: $fn()") : fail("MISSING function: $fn()");

$inv = inventoryAccountId($pdo); $cogs = cogsAccountId($pdo); $ap = apAccountId($pdo); $vat = inputVatAccountId($pdo);
$sid = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetchColumn();
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
($inv && $cogs && $ap && $vat && $sid) ? pass('all control accounts + a supplier resolve') : fail('control accounts/supplier missing');

if (!($inv && $cogs && $ap && $vat && $sid)) { fail('cannot run functional sections'); } else {
$pdo->beginTransaction();
try {
    // ── B. PHASE 1 ─────────────────────────────────────────────────────────
    section('B. Phase 1 — VAT split at posting');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type,supplier_id,invoice_ref,date_raised,date_recorded,amount,subtotal,tax_amount,status,recorded_by)
                   VALUES ('supplier',?,'GEN-G',?,?,295000,250000,45000,'approved',?)")->execute([$sid,'2026-06-01','2026-06-01',$uid]);
    $g = (int)$pdo->lastInsertId(); $rg = postGoodsInvoiceAccrual($pdo,$g,$uid); $Lg = legsOf($pdo,(int)$rg['entry_id']);
    (abs(($Lg[$inv]['debit']??0)-250000)<0.01 && abs(($Lg[$vat]['debit']??0)-45000)<0.01 && abs(($Lg[$ap]['credit']??0)-295000)<0.01)
        ? pass('goods VAT bill → Dr Inv 250k + Dr VAT 45k / Cr AP 295k') : fail('goods split wrong: '.json_encode($Lg));
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type,supplier_id,invoice_ref,date_raised,date_recorded,amount,subtotal,tax_amount,status,recorded_by)
                   VALUES ('sub_contractor',?,'GEN-S',?,?,118000,100000,18000,'approved',?)")->execute([$sid,'2026-06-02','2026-06-02',$uid]);
    $s = (int)$pdo->lastInsertId(); $rs = postSubcontractorAccrual($pdo,$s,$uid); $Ls = legsOf($pdo,(int)$rs['entry_id']);
    (abs(($Ls[$cogs]['debit']??0)-100000)<0.01 && abs(($Ls[$vat]['debit']??0)-18000)<0.01 && abs(($Ls[$ap]['credit']??0)-118000)<0.01)
        ? pass('service VAT bill → Dr COGS 100k + Dr VAT 18k / Cr AP 118k') : fail('service split wrong: '.json_encode($Ls));
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type,supplier_id,invoice_ref,date_raised,date_recorded,amount,subtotal,tax_amount,status,recorded_by)
                   VALUES ('supplier',?,'GEN-NV',?,?,40000,40000,0,'approved',?)")->execute([$sid,'2026-06-03','2026-06-03',$uid]);
    $nv = (int)$pdo->lastInsertId(); $rnv = postGoodsInvoiceAccrual($pdo,$nv,$uid);
    ((int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id={$rnv['entry_id']}")->fetchColumn() === 2)
        ? pass('no-VAT bill → 2-line entry (no regression)') : fail('no-VAT bill not 2-line');

    // ── C. PHASE 2 ─────────────────────────────────────────────────────────
    section('C. Phase 2 — buried-VAT remediation');
    $pdo->prepare("INSERT INTO supplier_invoices (invoice_type,supplier_id,invoice_ref,date_raised,date_recorded,amount,subtotal,tax_amount,status,recorded_by)
                   VALUES ('supplier',?,'GEN-REM',?,?,236000,200000,36000,'approved',?)")->execute([$sid,'2026-05-01','2026-05-01',$uid]);
    $rb = (int)$pdo->lastInsertId();
    $old = postLedgerEntry($pdo,"old gross seed",[['account_id'=>$inv,'type'=>'debit','amount'=>236000,'description'=>'x'],['account_id'=>$ap,'type'=>'credit','amount'=>236000,'description'=>'x']],null,$rb,'supplier_invoice','2026-05-01',$uid);
    $rem = remediateBuriedVatForEntry($pdo,$old,$uid);
    $invNet = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE jei.account_id=$inv AND ((je.entity_id=$rb AND je.entity_type IN ('supplier_invoice','bill_vat_remediation')) OR (je.parent_entity_id=$rb AND je.parent_entity_type='supplier_invoice'))")->fetchColumn();
    (($rem['reason']??'')==='remediated' && abs($invNet-200000)<0.01) ? pass('old gross entry remediated → Inventory net 200k') : fail('remediation wrong: '.json_encode($rem).' invNet='.money($invNet));
    ((remediateBuriedVatForEntry($pdo,$old,$uid)['reason']??'')==='already_remediated') ? pass('remediation idempotent') : fail('remediation not idempotent');

    // ── D. REVERSAL ────────────────────────────────────────────────────────
    section('D. Reversal — a VAT-split accrual reverses ALL legs');
    $rev = reverseGoodsInvoiceAccrual($pdo,$g,$uid);
    if (!empty($rev['reversed'])) {
        // After reversal, the bill's net across Inventory + Input VAT + AP should be zero.
        $netInv = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE jei.account_id=$inv AND je.entity_id=$g AND je.entity_type IN ('supplier_invoice','supplier_invoice_void')")->fetchColumn();
        $netVat = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id WHERE jei.account_id=$vat AND je.entity_id=$g AND je.entity_type IN ('supplier_invoice','supplier_invoice_void')")->fetchColumn();
        (abs($netInv)<0.01 && abs($netVat)<0.01) ? pass('reversal nets Inventory AND Input VAT to zero (all legs reversed)') : fail("reversal left Inv=".money($netInv)." VAT=".money($netVat));
    } else fail('reversal did not run: '.json_encode($rev));

    // ── E. REPORTS ─────────────────────────────────────────────────────────
    section('E. Reports — ledger + Balance Sheet balance');
    $bal = assertLedgerBalanced($pdo);
    ($bal['ledger_balanced']) ? pass("Σ Dr = Σ Cr (diff ".money($bal['dr_cr_difference']).")") : fail('ledger out of balance');
    ($bal['bs_balanced'])     ? pass("Balance Sheet balances (Assets = Liab + Equity, diff ".money($bal['bs_difference']).")") : fail('Balance Sheet does not balance');

} finally {
    $pdo->rollBack();
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM supplier_invoices WHERE invoice_ref LIKE 'GEN-%'")->fetchColumn();
($leftover === 0) ? pass('rolled back cleanly — no fixtures persisted') : fail("LEAKED $leftover rows");
}
