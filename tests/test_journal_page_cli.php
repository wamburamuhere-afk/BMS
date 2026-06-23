<?php
/**
 * tests/test_journal_page_cli.php
 * Exercises the General Journal page's data layer end-to-end against the live DB:
 *   create (save_journal) → list (get_journals) → reverse (reverse_journal),
 * then cleans up its own test rows. Self-contained + idempotent.
 *
 *   php tests/test_journal_page_cli.php
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function run_ep($root,$rel){ ob_start(); $prev=error_reporting(error_reporting()&~E_WARNING&~E_NOTICE); require $rel; error_reporting($prev); return ob_get_clean(); }

$REF = 'TEST-JRNL-' . date('YmdHis') . '-' . random_int(100,999);

// lint
foreach (['api/account/get_journals.php','api/account/reverse_journal.php','api/account/delete_journal.php','migrations/2026_06_22_journal_entries_transaction_id.php'] as $f) {
    $rc=0; exec("php -l ".escapeshellarg("$root/$f")." 2>&1",$o,$rc); ok($rc===0, "lint $f");
}

// two active accounts
$acc = $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
ok(count($acc)===2, 'have two active accounts to post against');
[$dr,$cr] = [$acc[0],$acc[1]];

// 1) CREATE via save_journal
$_SERVER['REQUEST_METHOD']='POST';
$_POST = [
    'entry_date'=>date('Y-m-d'), 'reference_number'=>$REF, 'description'=>'CLI test journal', 'status'=>'posted',
    'debit_accounts'=>[$dr], 'debit_amounts'=>['1000'], 'debit_descriptions'=>['test dr'],
    'credit_accounts'=>[$cr], 'credit_amounts'=>['1000'], 'credit_descriptions'=>['test cr'],
];
$res = json_decode(run_ep($root, "$root/api/account/save_journal.php"), true);
ok(!empty($res['success']), 'save_journal created a balanced journal: '.($res['message']??''));

$eid = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE reference_number=".$pdo->quote($REF))->fetchColumn();
ok($eid>0, "journal row exists (entry_id=$eid)");
$line = $pdo->query("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) FROM journal_entry_items WHERE entry_id=$eid")->fetchColumn();
ok(abs((float)$line)<0.01, 'journal lines balance (Dr=Cr)');
$txnId = $pdo->query("SELECT transaction_id FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
ok(!empty($txnId), 'transaction_id stored (column fix works)');
ok((int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=".(int)$txnId)->fetchColumn()>0, 'mirrored into books_transactions (registers the transaction)');

// 2) LIST via get_journals (search by our ref)
$_POST=[]; $_GET = ['draw'=>1,'start'=>0,'length'=>25,'search'=>['value'=>$REF]];
$list = json_decode(run_ep($root, "$root/api/account/get_journals.php"), true);
ok(isset($list['data']) && $list['recordsFiltered']>=1, 'get_journals returns the entry');
$found = false; foreach (($list['data']??[]) as $row){ if((int)$row['entry_id']===$eid){ $found=true;
    ok(abs($row['total_debits']-1000)<0.01 && abs($row['total_credits']-1000)<0.01, 'list row shows Dr=Cr=1000'); } }
ok($found, 'our journal appears in the list payload');
ok(isset($list['stats']['totalDebits'],$list['stats']['entryCount']), 'list returns summary stats');

// 3) REVERSE via reverse_journal
$_GET=[]; $_SERVER['REQUEST_METHOD']='POST'; $_POST=['entry_id'=>$eid];
$rev = json_decode(run_ep($root, "$root/api/account/reverse_journal.php"), true);
ok(!empty($rev['success']), 'reverse_journal posted the contra: '.($rev['message']??''));
$origStatus = $pdo->query("SELECT status FROM journal_entries WHERE entry_id=$eid")->fetchColumn();
ok($origStatus==='reversed', "original marked reversed");
$revId = (int)($rev['reversal_entry_id'] ?? 0);
$revBal = $pdo->query("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) FROM journal_entry_items WHERE entry_id=$revId")->fetchColumn();
ok($revId>0 && abs((float)$revBal)<0.01, 'reversal entry is balanced');

// CLEANUP — remove all test rows (originals + reversal + mirrors).
$ids = $pdo->query("SELECT entry_id FROM journal_entries WHERE reference_number LIKE 'TEST-JRNL-%' OR reference_number LIKE 'REV-TEST-JRNL-%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $cleanId) {
    $tx = $pdo->query("SELECT transaction_id FROM journal_entries WHERE entry_id=".(int)$cleanId)->fetchColumn();
    if ($tx) { $pdo->exec("DELETE FROM books_transactions WHERE transaction_id=".(int)$tx); $pdo->exec("DELETE FROM transactions WHERE transaction_id=".(int)$tx); }
    $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id=".(int)$cleanId);
    $pdo->exec("DELETE FROM journal_entries WHERE entry_id=".(int)$cleanId);
}
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_number LIKE 'TEST-JRNL-%' OR reference_number LIKE 'REV-TEST-JRNL-%'")->fetchColumn();
ok($leftover===0, 'cleanup removed all test journal rows');

echo "\nPasses: \033[32m$pass\033[0m   Failures: ".($fail?"\033[31m$fail\033[0m":"0")."\n";
exit($fail===0 ? 0 : 1);
