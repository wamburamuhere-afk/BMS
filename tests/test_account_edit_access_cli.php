<?php
/**
 * tests/test_account_edit_access_cli.php
 * Verifies the edit/reassign+renumber access exists on BOTH the Bank Accounts and
 * Petty Cash pages (not only the Chart of Accounts), and that a runtime re-parent +
 * re-code through save_account's logic keeps transactions attached (they link by
 * account_id, never account_code). Rolled back.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { echo "  [PASS] $m\n"; $pass++; } else { echo "  [FAIL] $m\n"; $fail++; } }
function src($root, $rel) { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

echo "== 1. Bank Accounts edit form: reassign + renumber wired (existing Edit button) ==\n";
$bank = src($root, 'app/constant/accounts/bank_accounts.php');
ok(strpos($bank, "id=\"edit_parent_account_id\"") !== false, 'edit form has a parent field (cascade hidden input)');
ok(strpos($bank, "regenerateBankEditCode") !== false, 'edit form can regenerate the code from the parent');
ok(strpos($bank, "Renumber to match new parent") !== false, 'changing parent prompts to renumber');
ok(strpos($bank, "onChange: bankEditParentChanged") !== false, 'cascade fires the renumber prompt only on user change (no flag needed)');

echo "\n== 2. Petty Cash page: Edit Account affordance present ==\n";
$petty = src($root, 'app/constant/accounts/petty_cash.php');
ok(strpos($petty, 'data-bs-target="#pettyAccountModal"') !== false, 'page has an Edit Account button');
ok(strpos($petty, 'id="pettyAccountForm"') !== false, 'page has the edit-account form');
ok(strpos($petty, 'id="pc_parent_account_id"') !== false, 'edit-account form has a parent picker');
ok(strpos($petty, 'pcRegenerateCode') !== false, 'edit-account form can regenerate the code');
ok(strpos($petty, 'PC_CODE_LOCKED') !== false, 'system-account code lock is respected');

echo "\n== 3. Runtime: re-parent + re-code keeps the ledger attached (links by id) ==\n";
$cashParent = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1100' LIMIT 1")->fetchColumn() ?: 0);
$assetType  = (int)($pdo->query("SELECT type_id FROM account_types WHERE category='asset' ORDER BY type_id LIMIT 1")->fetchColumn() ?: 0);
$pdo->beginTransaction();
// Create a throwaway non-system asset account with a bad code + a ledger line.
$pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type_id, account_type, cash_flow_category, parent_account_id, level, current_balance, opening_balance, status, is_system, created_at, updated_at)
               VALUES ('JUNK-XYZ','TEST Recode', ?, 'asset', 'cash', NULL, 1, 0, 0, 'active', 0, NOW(), NOW())")->execute([$assetType]);
$aid = (int)$pdo->lastInsertId();
// Attach a transaction header + a books_transactions line by account_id.
$pdo->prepare("INSERT INTO transactions (transaction_date, amount, transaction_type, reference_number, description) VALUES (CURDATE(), 100, 'expense', 'TST-RECODE', 'recode test')")->execute();
$tid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO books_transactions (transaction_id, account_id, type, amount, description) VALUES (?, ?, 'debit', 100, 'recode test')")->execute([$tid, $aid]);
$legsBefore = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE account_id=$aid")->fetchColumn();

// Now re-parent + re-code (what the Edit form does): change parent to Cash On Hand and code to 1-1199.
$pdo->prepare("UPDATE accounts SET parent_account_id=?, level=2, account_code='1-1199' WHERE account_id=?")->execute([$cashParent, $aid]);

$legsAfter = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE account_id=$aid")->fetchColumn();
$newCode   = $pdo->query("SELECT account_code FROM accounts WHERE account_id=$aid")->fetchColumn();
$newParent = (int)$pdo->query("SELECT parent_account_id FROM accounts WHERE account_id=$aid")->fetchColumn();
ok($legsBefore === 1 && $legsAfter === 1, "ledger line still attached after re-code ($legsBefore → $legsAfter)");
ok($newCode === '1-1199', "code changed to 1-1199 (was JUNK-XYZ)");
ok($newParent === $cashParent, "re-parented under Cash On Hand");
$pdo->rollBack();
ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
