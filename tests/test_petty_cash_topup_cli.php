<?php
/**
 * tests/test_petty_cash_topup_cli.php
 * Gap 2 regression: a petty-cash TOP-UP is now a real posted transfer
 *   Dr Petty Cash / Cr funding bank  → BOTH balances move, combined cash unchanged,
 *   and it is mirrored into the canonical journal (so it shows on the Chart of
 *   Accounts). Reversal restores both balances and removes the mirror. Expense
 *   path still works. All inside a rolled-back transaction.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

$pass = 0; $fail = 0;
function check($c, $m) { global $pass, $fail; if ($c) { echo "  [PASS] $m\n"; $pass++; } else { echo "  [FAIL] $m\n"; $fail++; } }
function bal(PDO $pdo, int $id): float { return (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$id")->fetchColumn(); }

$pettyId = pettyCashAccountId($pdo);
$source  = 0;
foreach (cashBankAccounts($pdo) as $a) { if ((int)$a['account_id'] !== (int)$pettyId) { $source = (int)$a['account_id']; break; } }
echo "pettyId=$pettyId source=$source\n\n";
if (!$pettyId || !$source) { echo "SKIP: petty cash account or a funding source not configured.\n"; exit(0); }

$amt = 5000.00;
$pdo->beginTransaction();

echo "== 1. Top-up posts a transfer (both balances move) ==\n";
$p0 = bal($pdo, $pettyId); $s0 = bal($pdo, $source); $je0 = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$txn = postPettyCashLedger($pdo, 'deposit', $amt, date('Y-m-d'), 'TEST-TOPUP', 'unit test top-up', $source);
check($txn > 0, "postPettyCashLedger(deposit) returned a txn id ($txn)");
$p1 = bal($pdo, $pettyId); $s1 = bal($pdo, $source);
check(abs(($p1 - $p0) - $amt) < 0.01, "petty cash rose by the amount (".round($p1-$p0,2).")");
check(abs(($s0 - $s1) - $amt) < 0.01, "funding account fell by the amount (".round($s0-$s1,2).")");
check(abs(($p1 + $s1) - ($p0 + $s0)) < 0.01, "combined cash unchanged (transfer, not creation)");

$eid = (int)$pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=".(int)$txn)->fetchColumn();
check($eid > 0, "top-up mirrored into the canonical journal (entry_id=$eid)");
$drcr = $pdo->query("SELECT ROUND(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),2) AS d FROM journal_entry_items WHERE entry_id=$eid")->fetchColumn();
check(abs((float)$drcr) < 0.01, "mirrored entry is balanced (Dr=Cr)");

echo "\n== 2. Reverse restores both balances + removes the mirror ==\n";
reversePettyCashLedger($pdo, 'deposit', $txn);
check(abs(bal($pdo,$pettyId) - $p0) < 0.01, "petty cash restored");
check(abs(bal($pdo,$source) - $s0) < 0.01, "funding account restored");
check((int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=".(int)$txn)->fetchColumn() === 0, "mirror removed on reverse");

echo "\n== 3. Expense path still works (petty cash falls; reverse restores) ==\n";
$pe0 = bal($pdo, $pettyId);
$txnE = postPettyCashLedger($pdo, 'expense', 3000.00, date('Y-m-d'), 'TEST-EXP', 'unit test expense', null);
check($txnE > 0, "postPettyCashLedger(expense) returned a txn id ($txnE)");
check(abs(($pe0 - bal($pdo,$pettyId)) - 3000.00) < 0.01, "petty cash fell by the expense amount");
reversePettyCashLedger($pdo, 'expense', $txnE);
check(abs(bal($pdo,$pettyId) - $pe0) < 0.01, "petty cash restored after expense reverse");

$pdo->rollBack();
echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
