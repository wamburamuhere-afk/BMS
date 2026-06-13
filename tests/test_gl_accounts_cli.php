<?php
/**
 * B0 — Shared GL account resolvers — CLI test
 *   php tests/test_gl_accounts_cli.php
 *
 * Guards core/gl_accounts.php (money_plan.md B0): each control-account resolver
 * returns an ACTIVE account (or a clean null), and where a postable account is
 * required it returns a LEAF (never a group header). Read-only.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/gl_accounts.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function isActive(PDO $pdo, ?int $id): bool {
    if (!$id) return false;
    return (bool)$pdo->query("SELECT 1 FROM accounts WHERE account_id=$id AND status='active'")->fetchColumn();
}
function isLeaf(PDO $pdo, ?int $id): bool {
    if (!$id) return false;
    return !(int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE parent_account_id=$id")->fetchColumn();
}
function categoryOf(PDO $pdo, ?int $id): ?string {
    if (!$id) return null;
    return $pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=$id")->fetchColumn() ?: null;
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Resolvers return active accounts (postable leaves where required)');
// [name, callable, requireLeaf, expectedCategory|null, required]
//   required=true  → must resolve (AR/Revenue are already wired by IN-3)
//   required=false → chart-dependent; null is acceptable ("not on this chart"), but a
//                    non-null result must still be a valid active account.
$cases = [
    ['arAccountId',                 fn() => arAccountId($pdo),                 false, 'asset',    true],
    ['salesRevenueAccountId',       fn() => salesRevenueAccountId($pdo),       true,  'revenue',  true],
    ['apAccountId',                 fn() => apAccountId($pdo),                 false, 'liability',false],
    ['inputVatAccountId',           fn() => inputVatAccountId($pdo),           false, 'asset',    false],
    ['inventoryAccountId',          fn() => inventoryAccountId($pdo),          false, 'asset',    false],
    ['cogsAccountId',               fn() => cogsAccountId($pdo),               true,  null,       false],
    ['salesReturnsAccountId',       fn() => salesReturnsAccountId($pdo),       false, null,       false],
    ['depreciationExpenseAccountId',fn() => depreciationExpenseAccountId($pdo),false, 'expense',  false],
];
foreach ($cases as [$name, $fn, $reqLeaf, $cat, $required]) {
    $id = $fn();
    if ($id === null) {
        $required ? fail("$name returned null (required account missing on this chart)")
                  : pass("$name → null (not on this local chart — n/a, resolver correct)");
        continue;
    }
    if (!isActive($pdo, $id)) { fail("$name → #$id is not active"); continue; }
    if ($reqLeaf && !isLeaf($pdo, $id)) { fail("$name → #$id is a group header (must be a postable leaf)"); continue; }
    if ($cat !== null && categoryOf($pdo, $id) !== $cat) { fail("$name → #$id category=" . categoryOf($pdo, $id) . " (want $cat)"); continue; }
    pass("$name → #$id active" . ($reqLeaf ? ', leaf' : '') . ($cat ? ", $cat" : ''));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. bankAccountResolve validates a cash/bank leaf, rejects others');
// a valid bank/cash leaf
$bank = (int)($pdo->query("SELECT a.account_id FROM accounts a
                             LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                            WHERE a.status='active' AND a.account_type='asset'
                              AND (st.is_bank=1 OR a.cash_flow_category='cash')
                              AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                            ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
if ($bank) {
    (bankAccountResolve($pdo, $bank) === $bank) ? pass("accepts a real cash/bank leaf (#$bank)") : fail("rejected a valid bank leaf #$bank");
} else { pass('no cash/bank leaf on this chart — accept-case skipped (n/a)'); }

// a non-cash account (a revenue account) must be rejected
$nonCash = salesRevenueAccountId($pdo);
(bankAccountResolve($pdo, $nonCash) === null) ? pass('rejects a non-cash account (revenue)') : fail('accepted a non-cash account as a bank');
(bankAccountResolve($pdo, null) === null)      ? pass('rejects null')                          : fail('did not reject null');
(bankAccountResolve($pdo, 999999999) === null) ? pass('rejects a non-existent id')             : fail('accepted a bogus id');

// ─────────────────────────────────────────────────────────────────────────
section('3. revenue_posting.php now sources its resolvers from gl_accounts.php');
$rp = file_get_contents("$root/core/revenue_posting.php");
(strpos($rp, "require_once __DIR__ . '/gl_accounts.php'") !== false)
    ? pass('revenue_posting includes gl_accounts.php (single home for AR/Revenue)')
    : fail('revenue_posting does not include gl_accounts.php');
(strpos($rp, 'function arAccountId') === false)
    ? pass('revenue_posting no longer redefines arAccountId (no duplication)')
    : fail('revenue_posting still redefines arAccountId (duplication risk)');
