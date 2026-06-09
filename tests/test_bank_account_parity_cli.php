<?php
/**
 * tests/test_bank_account_parity_cli.php
 * Gap 3 regression: the Bank Accounts form creates a PROPER cash account —
 *   - tagged cash_flow_category='cash' (so it appears in payment dropdowns),
 *   - nested under a parent (Cash On Hand),
 *   - with an auto-generated hierarchical code.
 * Source-asserts the form/endpoint wiring + a runtime check that a cash-tagged
 * leaf asset under Cash On Hand shows up in cashBankAccounts(). Rolled back.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { echo "  [PASS] $m\n"; $pass++; } else { echo "  [FAIL] $m\n"; $fail++; } }
function src($root, $rel) { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

echo "== 1. Form + endpoint wiring ==\n";
$form = src($root, 'app/constant/accounts/bank_accounts.php');
$api  = src($root, 'api/account/save_account.php');
ok(strpos($form, 'name="cash_flow_category" value="cash"') !== false, 'add form tags new accounts cash_flow_category=cash');
ok(strpos($form, 'id="add_account_code"') !== false && strpos($form, 'readonly') !== false, 'add form code is auto-generated (readonly)');
ok(strpos($form, 'generateBankCode(') !== false, 'add form wires the hierarchical code generator');
ok(strpos($form, 'id="add_parent_account_id"') !== false, 'add form has a Parent Account picker');
ok(strpos($form, '$default_cash_parent_id') !== false, 'parent defaults to Cash On Hand');
ok(strpos($api, 'cash_flow_category') !== false, 'save_account persists cash_flow_category');
ok(strpos($api, 'COALESCE(?, cash_flow_category)') !== false, 'update preserves cash_flow_category when not sent');

echo "\n== 2. Runtime: a cash-tagged leaf under Cash On Hand appears in cashBankAccounts() ==\n";
$cashParent = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='1-1100' LIMIT 1")->fetchColumn() ?: 0);
$assetType  = (int)($pdo->query("SELECT type_id FROM account_types WHERE category='asset' ORDER BY type_id LIMIT 1")->fetchColumn() ?: 0);
ok($cashParent > 0, "Cash On Hand parent found (id=$cashParent)");
ok($assetType > 0, "asset account_type found (id=$assetType)");

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type_id, account_type, cash_flow_category, parent_account_id, level, current_balance, opening_balance, status, created_at, updated_at)
               VALUES ('1-1199','TEST Bank Parity', ?, 'asset', 'cash', ?, 4, 0, 0, 'active', NOW(), NOW())")
    ->execute([$assetType, $cashParent]);
$newId = (int)$pdo->lastInsertId();

$inList = false;
foreach (cashBankAccounts($pdo) as $a) { if ((int)$a['account_id'] === $newId) { $inList = true; break; } }
ok($inList, 'new cash-tagged leaf account appears in cashBankAccounts() (payment dropdowns)');

// And it correctly nests under Cash On Hand (would roll up).
$parentOf = (int)$pdo->query("SELECT parent_account_id FROM accounts WHERE account_id=$newId")->fetchColumn();
ok($parentOf === $cashParent, 'new account is parented under Cash On Hand');

$pdo->rollBack();
ok(!$pdo->inTransaction(), 'rolled back — nothing persisted');

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
