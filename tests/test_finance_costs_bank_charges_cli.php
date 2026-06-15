<?php
/**
 * Finance Costs — bank charges wiring + loan-concept removal — CLI test
 *   php tests/test_finance_costs_bank_charges_cli.php
 *
 * Guards:
 *   - bankChargesAccountId() resolves a FINANCE_COST account (canonical 6-1900).
 *   - bank transfers default their charge account to it (so fees land in FINANCE COSTS).
 *   - a charge posted to that account lands in the glProfitLoss finance_cost bucket.
 *   - the loan-interest finance-cost account is retired (BMS has no loans).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/gl_accounts.php";
require_once "$root/core/ledger_post.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function has(string $hay, string $needle, string $label): void { strpos($hay,$needle)!==false ? pass($label) : fail("$label — missing"); }
register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $p=false; if($p)return; $p=true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

section('1. Files lint clean');
foreach (['core/gl_accounts.php','api/account/add_bank_transfer.php','app/constant/accounts/bank_transfers.php',
          'migrations/2026_06_15_finance_costs_bank_charges_setup.php'] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$o,$rc);
    $rc===0 ? pass("$f lints clean") : fail("php -l failed: $f");
}

section('2. bankChargesAccountId() resolves a finance_cost account');
$bc = bankChargesAccountId($pdo);
$bc ? pass("resolved bank charges account (#$bc)") : fail('bank charges account NOT resolved');
if ($bc) {
    $row = $pdo->query("SELECT a.account_code, at.category, a.status FROM accounts a
                          JOIN account_types at ON at.type_id=a.account_type_id WHERE a.account_id=$bc")->fetch(PDO::FETCH_ASSOC);
    ($row['category'] === 'finance_cost') ? pass("it is a FINANCE_COST account ({$row['account_code']})") : fail("category is {$row['category']}, not finance_cost");
    ($row['status'] === 'active') ? pass('it is active') : fail('bank charges account is not active');
}

section('3. Transfer charges default to the bank-charges account → FINANCE COSTS');
has(file_get_contents("$root/api/account/add_bank_transfer.php"), 'bankChargesAccountId($pdo)', 'add_bank_transfer defaults the charge account to bank charges');
has(file_get_contents("$root/app/constant/accounts/bank_transfers.php"), '$bank_charges_acc', 'transfer form pre-selects the bank-charges account');

section('4. Migration — seeds setting + retires loan interest (criteria + activity-guarded)');
$mig = file_get_contents("$root/migrations/2026_06_15_finance_costs_bank_charges_setup.php");
has($mig, 'default_bank_charges_account_id', 'seeds the canonical bank-charges setting');
has($mig, "category   = 'finance_cost'", 'retires only finance_cost accounts');
has($mig, "LIKE '%interest%'", 'targets loan-interest accounts by name');
has($mig, "je.status = 'posted'", 'activity guard — never retires an account carrying entries');

section('5. Runtime — a posted charge lands in the finance_cost bucket');
$cash = (int)($pdo->query("SELECT a.account_id FROM accounts a
                             LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                            WHERE a.status='active' AND a.account_type='asset'
                              AND (st.is_bank=1 OR a.cash_flow_category='cash')
                              AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                            ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
if ($bc && $cash && $uid) {
    $pdo->beginTransaction();
    $today = date('Y-m-d');
    $before = glProfitLoss($pdo, $today, $today)['total_finance_cost'];
    postLedgerEntry($pdo, 'Test bank charge', [
        ['account_id' => $bc,   'type' => 'debit',  'amount' => 1500.00, 'description' => 'Bank charge'],
        ['account_id' => $cash, 'type' => 'credit', 'amount' => 1500.00, 'description' => 'From bank'],
    ], null, null, 'test_bank_charge', $today, $uid);
    $after = glProfitLoss($pdo, $today, $today)['total_finance_cost'];
    (abs(($after - $before) - 1500.00) < 0.01) ? pass('charge increased FINANCE COSTS by 1,500') : fail("finance cost moved by ".($after-$before).", expected 1500");
    $pdo->rollBack();
} else { fail('could not resolve bank-charges/cash/user for the runtime test'); }

section('6. Loan-interest account retired, bank charges active');
$interestActive = (int)$pdo->query("SELECT COUNT(*) FROM accounts a JOIN account_types at ON at.type_id=a.account_type_id
                                     WHERE at.category='finance_cost' AND a.account_name LIKE '%interest%'
                                       AND a.status='active' AND COALESCE(a.is_system,0)=0
                                       AND NOT EXISTS (SELECT 1 FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id
                                                        WHERE jei.account_id=a.account_id AND je.status='posted')")->fetchColumn();
$interestActive === 0 ? pass('no unused loan-interest finance-cost account left active') : fail("$interestActive loan-interest account(s) still active");
