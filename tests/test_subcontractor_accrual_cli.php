<?php
/**
 * OUT-3 — Sub-contractor invoice COGS accrual — CLI test
 *   php tests/test_subcontractor_accrual_cli.php
 *
 * Guards core/purchase_posting.php::postSubcontractorAccrual: an approved
 * sub-contractor supplier invoice posts Dr COGS / Cr Accounts Payable so the
 * construction COGS reaches the GL accrually (matching what the Income Statement
 * reads), idempotent, reversible, and supplier-goods invoices are skipped.
 * Rolled-back transactions — the database is left untouched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/purchase_posting.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});
function netByAcct(PDO $pdo, int $eid): array {
    $m=[]; foreach($pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid") as $r){
        $a=(int)$r['account_id']; $m[$a]=($m[$a]??0)+($r['type']==='debit'?(float)$r['amount']:-(float)$r['amount']); } return $m;
}

$uid  = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$cogs = cogsAccountId($pdo);
$ap   = apAccountId($pdo);

section('1. Control accounts resolve');
$cogs ? pass("cogsAccountId → #$cogs") : fail('cogsAccountId null');
$ap   ? pass("apAccountId → #$ap")     : fail('apAccountId null');

section('2. Sub-contractor invoice posts Dr COGS / Cr AP (rolled back)');
$sc = $pdo->query("SELECT id, amount FROM supplier_invoices WHERE invoice_type='sub_contractor' AND amount>0 AND amount<100000000 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$sc) { fail('no sub_contractor invoice with a sane amount — cannot run'); return; }
$sid = (int)$sc['id']; $amt = round((float)$sc['amount'], 2);
echo "   using sub_contractor invoice #$sid amount=" . money($amt) . "\n";

$pdo->beginTransaction();
try {
    $r = postSubcontractorAccrual($pdo, $sid, $uid);
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("posted (entry #{$r['entry_id']})") : fail('did not post: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $eid = (int)$r['entry_id'];
        $net = netByAcct($pdo, $eid);
        $et  = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
        (abs(($net[(int)$cogs]??0) - $amt) < 0.01) ? pass('COGS debited '.money($amt))   : fail('COGS debit wrong: '.json_encode($net));
        (abs(($net[(int)$ap]??0) + $amt) < 0.01)   ? pass('Accounts Payable credited '.money($amt)) : fail('AP credit wrong');
        ($et === 'subcontractor_invoice') ? pass("entity_type='subcontractor_invoice'") : fail("entity_type=$et");
        $r2 = postSubcontractorAccrual($pdo, $sid, $uid);
        (($r2['reason']??'')==='already_posted') ? pass('idempotent') : fail('not idempotent: '.($r2['reason']??'?'));
        // reversal
        $rev = reverseSubcontractorAccrual($pdo, $sid, $uid);
        (!empty($rev['reversed'])) ? pass('reversible (delete/push-back)') : fail('reverse failed: '.($rev['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

section('3. Supplier (goods) invoices are skipped (GRN raises their AP)');
$sup = (int)($pdo->query("SELECT id FROM supplier_invoices WHERE invoice_type='supplier' AND amount>0 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($sup) {
    $pdo->beginTransaction();
    try {
        $r = postSubcontractorAccrual($pdo, $sup, $uid);
        (($r['reason']??'')==='not_subcontractor') ? pass("supplier invoice #$sup skipped (not_subcontractor)") : fail('supplier invoice not skipped: '.json_encode($r));
    } finally { $pdo->rollBack(); }
} else { pass('no supplier-goods invoice present — skip-case n/a'); }

section('4. Ledger still balances');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance');
