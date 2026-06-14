<?php
/**
 * OUT-15 — IPC contract revenue posting — CLI test
 *   php tests/test_ipc_posting_cli.php
 *
 * Guards core/ipc_posting.php + the IN-3 mutual-exclusion guard:
 *   - an Approved IPC posts Dr Accounts Receivable / Cr Contract Revenue (net_payable)
 *   - idempotent on (entity_type='ipc', ipc_id)
 *   - postInvoiceRevenue() skips an invoice already recognised via its IPC
 * All posts run inside ROLLED-BACK transactions — the database is left untouched.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ipc_posting.php";
require_once "$root/core/revenue_posting.php";
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
section('1. Control accounts resolve');
$ar  = arAccountId($pdo);
$rev = contractRevenueAccountId($pdo);
$ar  ? pass("arAccountId → #$ar")               : fail('arAccountId null');
$rev ? pass("contractRevenueAccountId → #$rev") : fail('contractRevenueAccountId null');

// ─────────────────────────────────────────────────────────────────────────
section('2. Approved IPC posts Dr AR / Cr Contract Revenue (rolled back)');
$ipc = $pdo->query("SELECT ipc_id, ipc_number, net_payable, certified_amount
                      FROM interim_payment_certificates
                     WHERE status='Approved' AND invoice_id IS NULL
                       AND COALESCE(net_payable, certified_amount) > 0
                  ORDER BY net_payable DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

if (!$ipc) { fail('no Approved standalone IPC with an amount — cannot run functional test'); return; }
$rid = (int)$ipc['ipc_id'];
$amt = round((float)($ipc['net_payable'] ?: $ipc['certified_amount']), 2);
echo "   using IPC #$rid {$ipc['ipc_number']}  net_payable=" . money($amt) . "\n";

$beforeIpc = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='ipc' AND status='posted'")->fetchColumn();

$pdo->beginTransaction();
try {
    $r = postIpcRevenue($pdo, $rid, $uid);
    (!empty($r['posted']) && !empty($r['entry_id'])) ? pass("posted (entry #{$r['entry_id']})") : fail('did not post: ' . ($r['reason'] ?? '?'));
    if (!empty($r['entry_id'])) {
        $eid = (int)$r['entry_id'];
        $rows = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
        $et = $pdo->query("SELECT entity_type FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
        $dr = 0.0; $cr = 0.0; $drAcc = null; $crAcc = null;
        foreach ($rows as $l) { if ($l['type']==='debit'){$dr=(float)$l['amount'];$drAcc=(int)$l['account_id'];} else {$cr=(float)$l['amount'];$crAcc=(int)$l['account_id'];} }
        (count($rows)===2 && abs($dr-$cr)<0.01 && abs($dr-$amt)<0.01) ? pass("2 lines, balanced @ " . money($amt)) : fail("shape wrong dr=$dr cr=$cr amt=$amt");
        ($drAcc === (int)$ar)  ? pass('Accounts Receivable debited') : fail("debit #$drAcc != AR #$ar");
        ($crAcc === (int)$rev) ? pass('Contract Revenue credited')   : fail("credit #$crAcc != Revenue #$rev");
        ($et === 'ipc')        ? pass("entity_type='ipc'")           : fail("entity_type=$et");

        $r2 = postIpcRevenue($pdo, $rid, $uid);
        (($r2['reason'] ?? '')==='already_posted' && (int)$r2['entry_id']===$eid) ? pass('idempotent (already_posted)') : fail('not idempotent: '.($r2['reason']??'?'));
    }
} finally { $pdo->rollBack(); }

$afterIpc = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='ipc' AND status='posted'")->fetchColumn();
($afterIpc === $beforeIpc) ? pass("rolled back cleanly ($beforeIpc → $afterIpc)") : fail("LEAKED: $beforeIpc → $afterIpc");

// ─────────────────────────────────────────────────────────────────────────
section('3. postInvoiceRevenue skips an IPC-recognised invoice (no double-count)');
$paid = $pdo->query("SELECT ipc_id, invoice_id FROM interim_payment_certificates
                      WHERE invoice_id IS NOT NULL ORDER BY ipc_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$paid) {
    pass('no IPC-linked invoice present — exclusion case n/a on this data');
} else {
    $pdo->beginTransaction();
    try {
        // Recognise the IPC, then ask the invoice path to post — it must defer to the IPC.
        $ri = postIpcRevenue($pdo, (int)$paid['ipc_id'], $uid);
        $inv = postInvoiceRevenue($pdo, (int)$paid['invoice_id'], $uid);
        (($inv['reason'] ?? '') === 'recognised_via_ipc')
            ? pass("invoice #{$paid['invoice_id']} deferred to IPC #{$paid['ipc_id']} (recognised_via_ipc)")
            : fail('invoice did NOT defer to IPC: ' . json_encode($inv));
    } finally { $pdo->rollBack(); }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Ledger still balances');
$g = assertLedgerBalanced($pdo);
$g['ok'] ? pass('assertLedgerBalanced ok') : fail('ledger out of balance: ' . json_encode($g));
