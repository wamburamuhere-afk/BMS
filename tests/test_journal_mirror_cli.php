<?php
/**
 * tests/test_journal_mirror_cli.php
 * Gap 1 regression: every money-engine posting is mirrored into the canonical
 * journal_entries ledger (the one reports + the Chart of Accounts read), the
 * mirror is BALANCE-NEUTRAL (no double effect on current_balance), and a reversal
 * cleanly removes the mirror. All inside a rolled-back transaction.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

$pass = 0; $fail = 0;
function check($cond, $msg) { global $pass, $fail; if ($cond) { echo "  [PASS] $msg\n"; $pass++; } else { echo "  [FAIL] $msg\n"; $fail++; } }

$paidFrom = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE at.category='asset' AND a.status='active' ORDER BY a.account_id LIMIT 1")->fetchColumn();
$debitAcc = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE at.category='expense' AND a.status='active' ORDER BY a.account_id LIMIT 1")->fetchColumn();
echo "Using paidFrom=$paidFrom debitAcc=$debitAcc\n\n";

$amount = 12345.67;
$pdo->beginTransaction();

$jeBefore  = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$balBefore = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$paidFrom")->fetchColumn();

echo "== 1. postOutflow mirrors into the canonical journal ==\n";
$txn = postOutflow($pdo, 'expense', $paidFrom, $debitAcc, $amount, date('Y-m-d'), 'TEST-MIRROR', 'Mirror test expense', null);
check($txn > 0, "postOutflow returned a transaction id ($txn)");

$eid = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=".(int)$txn)->fetchColumn();
check($eid > 0, "canonical journal entry created (entry_id=$eid)");

$row = $pdo->query("SELECT
   (SELECT status FROM journal_entries WHERE entry_id=$eid) AS status,
   (SELECT COALESCE(SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END),0) FROM journal_entry_items WHERE entry_id=$eid) AS dr,
   (SELECT COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE 0 END),0) FROM journal_entry_items WHERE entry_id=$eid) AS cr
")->fetch(PDO::FETCH_ASSOC);
check($row['status']==='posted', "journal entry status = posted");
check(abs($row['dr']-$row['cr'])<0.01, "journal entry balanced (Dr {$row['dr']} = Cr {$row['cr']})");
check(abs($row['dr']-$amount)<0.01, "journal debit total == amount ($amount)");

echo "\n== 2. Balance moved EXACTLY once (mirror is balance-neutral) ==\n";
$balAfter = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$paidFrom")->fetchColumn();
check(abs(($balBefore - $balAfter) - $amount) < 0.01, "paid-from reduced by exactly the amount (delta=".round($balBefore-$balAfter,2).")");
$jeAfter = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
check(($jeAfter - $jeBefore) === 1, "exactly one journal entry added (".($jeAfter-$jeBefore).")");

echo "\n== 3. Reverse removes the mirror and restores balance ==\n";
reverseOutflow($pdo, $txn);
$gone = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=".(int)$txn)->fetchColumn();
check($gone === 0, "reverseOutflow removed the mirrored journal entry");
$balRestored = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$paidFrom")->fetchColumn();
check(abs($balRestored - $balBefore) < 0.01, "balance restored after reverse (".round($balRestored,2)." == ".round($balBefore,2).")");

$pdo->rollBack();
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
