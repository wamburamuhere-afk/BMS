<?php
/**
 * Standalone Revenue / Other Income (Plan 3) — CLI test
 *   php tests/test_revenue_posting_cli.php
 *
 * Verifies: files exist + lint; migration applied (tables + enum + perms + default
 * categories); create endpoint records a revenue and moves NO money; the post logic
 * receives the money (Dr bank / Cr income + a deposit register row); void reverses;
 * and the income-statement + cash-flow integrations are wired. Runtime on rollback.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";   // postInflow / reverseInflow, cashBankAccounts
require_once "$root/core/bank_register.php";     // recordBankTransaction / reverse
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function callPost(string $root, string $rel, array $post) {
    $_POST = $post; $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['_csrf'] = $_SESSION['csrf_token'] ?? '';
    ob_start();
    include "$root/$rel";
    return json_decode(ob_get_clean(), true);
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'migrations/2026_06_08_revenue.php',
    'api/account/add_revenue.php', 'api/account/update_revenue_status.php',
    'api/finance/get_revenue_schema.php', 'api/finance/manage_revenue_schema.php',
    'app/constant/accounts/revenue.php', 'app/constant/accounts/revenue_categories.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration applied — tables + enum + perms + route/menu');
($pdo->query("SHOW TABLES LIKE 'revenues'")->fetch()) ? pass('revenues table exists') : fail('revenues table missing');
($pdo->query("SHOW TABLES LIKE 'revenue_categories'")->fetch()) ? pass('revenue_categories table exists') : fail('revenue_categories table missing');
$enum = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
(strpos($enum['Type'], "'revenue'") !== false) ? pass("'revenue' present in transactions.transaction_type") : fail("'revenue' missing from enum");
((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key='revenue'")->fetchColumn() === 1) ? pass('revenue permission seeded') : fail('revenue permission missing');
((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key='revenue_categories'")->fetchColumn() === 1) ? pass('revenue_categories permission seeded') : fail('revenue_categories permission missing');
((int)$pdo->query("SELECT COUNT(*) FROM revenue_categories")->fetchColumn() >= 5) ? pass('default revenue categories seeded') : fail('default categories not seeded');
has(src($root, 'roots.php'), "'revenue' => ACCOUNTS_DIR . '/revenue.php'", 'revenue route registered');
has(src($root, 'roots.php'), "'revenue_categories' => ACCOUNTS_DIR . '/revenue_categories.php'", 'revenue_categories route registered');
has(src($root, 'header.php'), "getUrl('revenue')", 'revenue menu link present');

// ─────────────────────────────────────────────────────────────────────────
section('3. API contracts — post-gated; post via postInflow; void; idempotent');
$add = src($root, 'api/account/add_revenue.php');
has($add, "canCreate('revenue')", 'add gated by canCreate');
has($add, "csrf_check()", 'add enforces CSRF');
hasnt($add, "postInflow", 'add moves NO money at create');
$st = src($root, 'api/account/update_revenue_status.php');
has($st, "postInflow(\$pdo, 'revenue'", 'post receives money via postInflow');
has($st, "recordBankTransaction(\$pdo, \$bank, \$amount, 'deposit'", 'post writes a deposit register row');
has($st, "empty(\$r['transaction_id'])", 'post is idempotent');
has($st, "reverseInflow(\$pdo", 'void reverses the cash receipt');
has($st, "reverseBankTransaction(\$pdo, \$bank, \$ref, 'deposit')", 'void reverses the register deposit');
has($st, "canReview('revenue')", 'review gated by canReview');
has($st, "canApprove('revenue')", 'approve gated by canApprove');

// ─────────────────────────────────────────────────────────────────────────
section('4. Reports integration (additive)');
$is = src($root, 'api/account/get_income_statement.php');
// Post-F3 flip: the income statement is single-source (GL). Standalone/other revenues
// reach the P&L as POSTED revenue journal entries (IN-4), picked up by glProfitLoss —
// not via a document scan of the `revenues` table.
has($is, "glProfitLoss(", 'income statement is GL-sourced (posted revenue entries, incl. standalone revenues)');
has($is, "'general_ledger'", 'income statement marks the GL as its single source');
$cf = src($root, 'api/account/get_cash_flow.php');
has($cf, "revenue_received", 'cash flow captures revenue inflow');
has($cf, "Other income received", 'cash flow adds an operating inflow line');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — create endpoint records a revenue but moves NO money');
$_SESSION = ['user_id' => 4, 'role_id' => 1, 'username' => 'cli-test', 'csrf_token' => 'testtok',
             'first_name' => 'CLI', 'last_name' => 'Test', 'user_role' => 'Admin'];
$bank   = (int)cashBankAccounts($pdo)[0]['account_id'];
$income = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='income' LIMIT 1")->fetchColumn();
if ($income <= 0) { fail('no income account available to test'); }
else {
    try {
        $pdo->beginTransaction();
        $bal0 = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        $res = callPost($root, 'api/account/add_revenue.php', [
            'revenue_date' => date('Y-m-d'), 'income_account_id' => $income, 'bank_account_id' => $bank,
            'amount' => 500, 'description' => '__REV_TEST__', 'payer_name' => 'Tester',
        ]);
        (is_array($res) && !empty($res['success'])) ? pass('create endpoint returned success') : fail('create failed: ' . json_encode($res));
        $balN = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        (abs($balN - $bal0) < 0.001) ? pass('create moved NO money (balance unchanged)') : fail("create moved money ($bal0 -> $balN)");
        $row = $pdo->query("SELECT status, transaction_id FROM revenues WHERE description='__REV_TEST__' ORDER BY revenue_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        ($row && $row['status'] === 'pending' && empty($row['transaction_id'])) ? pass('create left it pending with no transaction_id') : fail('unexpected created state: ' . json_encode($row));
        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('create runtime error: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Runtime — post receives money + deposit register; void reverses');
if ($income > 0) {
    try {
        $pdo->beginTransaction();
        $bal0 = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
        $amount = 500.0; $ref = 'REV-TEST-' . time();

        $txn = postInflow($pdo, 'revenue', $bank, $income, $amount, date('Y-m-d'), $ref, 'rev', null);
        if (!$txn) { fail('postInflow returned null'); $pdo->rollBack(); }
        else {
            recordBankTransaction($pdo, $bank, $amount, 'deposit', date('Y-m-d'), $ref, 'rev', 4);

            $bal1 = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
            (abs($bal1 - ($bal0 + $amount)) < 0.001) ? pass('post raised the bank balance by the amount') : fail("balance wrong ($bal1 vs " . ($bal0 + $amount) . ")");
            ((int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn() === 2) ? pass('post wrote a balanced 2-line entry (Dr bank / Cr income)') : fail('expected 2 ledger lines');
            $reg = $pdo->query("SELECT amount, transaction_type FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetch(PDO::FETCH_ASSOC);
            ($reg && (float)$reg['amount'] === $amount && $reg['transaction_type'] === 'deposit') ? pass('post wrote a deposit register row') : fail('no deposit register row');

            // Void
            reverseInflow($pdo, $txn);
            reverseBankTransaction($pdo, $bank, $ref, 'deposit');
            $bal2 = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$bank")->fetchColumn();
            (abs($bal2 - $bal0) < 0.001) ? pass('void restored the bank balance') : fail("void balance wrong ($bal2 vs $bal0)");
            ((int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn() === 0) ? pass('void removed the register row') : fail('register row remained');

            $pdo->rollBack();
            pass('post/void cycle rolled back (no persistence)');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('post/void runtime error: ' . $e->getMessage());
    }
}
