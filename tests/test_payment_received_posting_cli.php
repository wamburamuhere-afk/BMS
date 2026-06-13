<?php
/**
 * B1 / IN-1 + IN-2 — Customer payment posting — CLI test
 *   php tests/test_payment_received_posting_cli.php
 *
 * Guards money.md IN-1 (record_payment.php) & IN-2 (save_receipt.php): a completed
 * customer payment posts ONE balanced entry into the canonical ledger —
 * Dr Received-Into (net) [+ Dr WHT Receivable] / Cr Accounts Receivable (gross) —
 * via core/money_in_posting.php::postPaymentReceived(). Was: AR from an empty
 * journal_mappings row → gated no-op (no GL entry); save_receipt posted nothing.
 *
 * Verifies: both endpoints call postPaymentReceived; the helper posts via the canonical
 * ledger, idempotent, no current_balance nudge; runtime posts a balanced 2-line (non-WHT)
 * and 3-line (WHT) entry, AR credit == gross, bank debit == net, idempotent. Rolled back.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_in_posting.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 50) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — still has `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint + both payment paths post via the shared helper');
foreach (['core/money_in_posting.php', 'api/account/record_payment.php', 'api/account/save_receipt.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}
$rec = src($root, 'api/account/record_payment.php');
$rcp = src($root, 'api/account/save_receipt.php');
has($rec, 'postPaymentReceived', 'record_payment.php posts via postPaymentReceived');
has($rcp, 'postPaymentReceived', 'save_receipt.php posts via postPaymentReceived');
hasnt($rec, "applyAccountBalanceDelta", 'record_payment.php no longer nudges current_balance');
$mi = src($root, 'core/money_in_posting.php');
has($mi, 'postLedgerEntry', 'helper posts via the canonical ledger (postLedgerEntry)');
has($mi, "entity_type = ? AND entity_id = ?", 'helper is idempotent on (entity_type, entity_id)');
hasnt($mi, 'applyAccountBalanceDelta', 'helper never touches current_balance');

// ─────────────────────────────────────────────────────────────────────────
section('2. Runtime — balanced entry, WHT split, idempotent (rolled back)');
$uid  = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$bank = (int)($pdo->query("SELECT a.account_id FROM accounts a LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                            WHERE a.status='active' AND a.account_type='asset' AND (st.is_bank=1 OR a.cash_flow_category='cash')
                              AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                            ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
$ar   = arAccountId($pdo);
$wht  = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code IN ('1-1980','1-6000') AND status='active' LIMIT 1")->fetchColumn() ?: 0);

if (!$bank || !$ar) { fail('no bank or AR account to test against'); return; }

$pdo->beginTransaction();
// unique synthetic payment ids (won't collide with real ones)
$pid1 = 900000001; $pid2 = 900000002;
$pdo->prepare("DELETE ji FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id WHERE je.entity_type='payment' AND je.entity_id IN (?,?)")->execute([$pid1, $pid2]);
$pdo->prepare("DELETE FROM journal_entries WHERE entity_type='payment' AND entity_id IN (?,?)")->execute([$pid1, $pid2]);

// non-WHT
$r1 = postPaymentReceived($pdo, $pid1, $bank, 100000.00, date('Y-m-d'), 'PAY-T1', 'test payment', null, $uid);
if (empty($r1['posted'])) { fail('non-WHT payment did not post (' . ($r1['reason'] ?? '?') . ')'); }
else {
    $rows = $pdo->query("SELECT type, amount, account_id FROM journal_entry_items WHERE entry_id=" . (int)$r1['entry_id'])->fetchAll(PDO::FETCH_ASSOC);
    $dr = 0; $cr = 0; $arCr = 0;
    foreach ($rows as $r) { if ($r['type']==='debit') $dr += (float)$r['amount']; else { $cr += (float)$r['amount']; if ((int)$r['account_id']===(int)$ar) $arCr += (float)$r['amount']; } }
    (count($rows) === 2) ? pass('non-WHT → 2-line entry') : fail('non-WHT → expected 2 lines, got ' . count($rows));
    (abs($dr - $cr) < 0.01) ? pass('non-WHT balanced (Dr=Cr=' . number_format($dr, 2) . ')') : fail("non-WHT unbalanced Dr=$dr Cr=$cr");
    (abs($arCr - 100000.00) < 0.01) ? pass('non-WHT AR credit == gross (100,000)') : fail("AR credit $arCr != 100000");
    (($r2 = postPaymentReceived($pdo, $pid1, $bank, 100000.00, date('Y-m-d'), 'PAY-T1', 'dup', null, $uid))['reason'] ?? '') === 'already_posted'
        ? pass('non-WHT idempotent (no double-post)') : fail('non-WHT NOT idempotent');
}

// WHT
if ($wht) {
    $rw = postPaymentReceived($pdo, $pid2, $bank, 100000.00, date('Y-m-d'), 'PAY-T2', 'test wht payment', null, $uid, 5000.00, $wht);
    if (empty($rw['posted'])) { fail('WHT payment did not post (' . ($rw['reason'] ?? '?') . ')'); }
    else {
        $rows = $pdo->query("SELECT type, amount, account_id FROM journal_entry_items WHERE entry_id=" . (int)$rw['entry_id'])->fetchAll(PDO::FETCH_ASSOC);
        $dr = 0; $cr = 0; $bankDr = 0;
        foreach ($rows as $r) { if ($r['type']==='debit') { $dr += (float)$r['amount']; if ((int)$r['account_id']===(int)$bank) $bankDr += (float)$r['amount']; } else $cr += (float)$r['amount']; }
        (count($rows) === 3) ? pass('WHT → 3-line entry') : fail('WHT → expected 3 lines, got ' . count($rows));
        (abs($dr - $cr) < 0.01) ? pass('WHT balanced (Dr=Cr=' . number_format($dr, 2) . ')') : fail("WHT unbalanced Dr=$dr Cr=$cr");
        (abs($bankDr - 95000.00) < 0.01) ? pass('WHT bank debit == net (95,000 = 100,000 − 5,000)') : fail("bank debit $bankDr != 95000");
    }
} else {
    pass('no WHT Receivable account on this chart — WHT case skipped (n/a)');
}
$pdo->rollBack();
