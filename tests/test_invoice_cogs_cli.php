<?php
/**
 * IS Phase 2 — Invoice COGS posting — CLI test
 *   php tests/test_invoice_cogs_cli.php
 *
 * Guards core/revenue_posting.php::postInvoiceCOGS: when a product invoice is
 * approved, the cost of goods sold posts Dr Cost of Goods Sold / Cr Inventory
 * (Σ qty × products.cost_price), matched to the revenue period — idempotent,
 * reversible, with no double-count against the POS path. Rolled-back transactions.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/revenue_posting.php";
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
$inv  = inventoryAccountId($pdo);

section('1. Control accounts resolve, and COGS account is category=cogs');
$cogs ? pass("cogsAccountId → #$cogs") : fail('cogsAccountId null');
$inv  ? pass("inventoryAccountId → #$inv") : fail('inventoryAccountId null');
$cat = $pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON at.type_id=a.account_type_id WHERE a.account_id=".(int)$cogs)->fetchColumn();
($cat === 'cogs') ? pass("COGS account is classified 'cogs' (will show in the COGS section)") : fail("COGS account category='$cat' (not cogs — won't show in COGS section)");

section('2. postInvoiceCOGS posts Dr COGS / Cr Inventory = Σ qty×cost (rolled back)');
$row = $pdo->query("
    SELECT i.invoice_id, i.invoice_number, COALESCE(SUM(ii.quantity*COALESCE(p.cost_price,0)),0) cogs
      FROM invoices i JOIN invoice_items ii ON ii.invoice_id=i.invoice_id JOIN products p ON p.product_id=ii.product_id
     WHERE i.status NOT IN ('cancelled','rejected','deleted','draft') AND ii.product_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM pos_sales ps WHERE ps.invoice_id=i.invoice_id)
  GROUP BY i.invoice_id HAVING cogs>0 ORDER BY cogs DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { fail('no product invoice with COGS found — cannot run'); return; }
$iid = (int)$row['invoice_id']; $expected = round((float)$row['cogs'], 2);
echo "   using invoice #$iid {$row['invoice_number']}  expected COGS=" . money($expected) . "\n";

(abs(invoiceCogsValue($pdo, $iid) - $expected) < 0.01) ? pass('invoiceCogsValue = Σ qty×cost') : fail('invoiceCogsValue mismatch');

$pdo->beginTransaction();
try {
    $r = postInvoiceCOGS($pdo, $iid, $uid);
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("posted (entry #{$r['entry_id']})") : fail('did not post: '.($r['reason']??'?'));
    if (!empty($r['entry_id'])) {
        $eid = (int)$r['entry_id']; $net = netByAcct($pdo, $eid);
        $et  = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
        (abs(($net[(int)$cogs]??0) - $expected) < 0.01) ? pass('COGS debited '.money($expected)) : fail('COGS debit wrong: '.json_encode($net));
        (abs(($net[(int)$inv]??0) + $expected) < 0.01) ? pass('Inventory credited '.money($expected)) : fail('Inventory credit wrong');
        ($et === 'invoice_cogs') ? pass("entity_type='invoice_cogs'") : fail("entity_type=$et");
        $r2 = postInvoiceCOGS($pdo, $iid, $uid);
        (($r2['reason']??'')==='already_posted') ? pass('idempotent') : fail('not idempotent: '.($r2['reason']??'?'));
        $rev = reverseInvoiceCOGS($pdo, $iid, $uid);
        (!empty($rev['reversed'])) ? pass('reversible (cancel/void)') : fail('reverse failed: '.($rev['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

section('3. Service / IPC invoice (no product lines) posts nothing');
$svc = (int)($pdo->query("
    SELECT i.invoice_id FROM invoices i
     WHERE i.status NOT IN ('cancelled','rejected','deleted','draft')
       AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id=i.invoice_id AND ii.product_id IS NOT NULL)
     ORDER BY i.invoice_id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($svc) {
    $pdo->beginTransaction();
    try { $r = postInvoiceCOGS($pdo, $svc, $uid);
        (($r['reason']??'')==='no_cogs') ? pass("service invoice #$svc → no_cogs (skipped)") : fail('service invoice not skipped: '.json_encode($r));
    } finally { $pdo->rollBack(); }
} else { pass('no service-only invoice present — case n/a'); }

section('4. Ledger still balances');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance');
