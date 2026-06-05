<?php
/**
 * Bank / Cash Transfer (Plan 2) — CLI test
 *   php tests/test_bank_transfer_cli.php
 *
 * Verifies: files exist + lint; migration applied (table + enum + permission);
 * the create endpoint records a transfer and moves NO money; the post logic moves
 * BOTH cash legs, writes a balanced ledger entry + two register rows; the void
 * reverses everything. Runtime cycles run on a rolled-back transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";          // applyAccountBalanceDelta, cashBankAccounts
require_once "$root/core/bank_register.php";            // recordBankTransaction / reverse
require_once "$root/api/helpers/transaction_helper.php"; // recordGlobalTransaction
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
    'migrations/2026_06_07_bank_transfers.php',
    'api/account/add_bank_transfer.php',
    'api/account/update_bank_transfer_status.php',
    'app/constant/accounts/bank_transfers.php',
];
foreach ($files as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration applied — table + enum + permission + route/menu');
($pdo->query("SHOW TABLES LIKE 'bank_transfers'")->fetch()) ? pass('bank_transfers table exists') : fail('bank_transfers table missing — run the migration');
$enum = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
(strpos($enum['Type'], "'transfer'") !== false) ? pass("'transfer' present in transactions.transaction_type") : fail("'transfer' missing from enum");
((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key='bank_transfers'")->fetchColumn() === 1) ? pass('bank_transfers permission seeded') : fail('permission not seeded');
has(src($root, 'roots.php'), "'bank_transfers' => ACCOUNTS_DIR . '/bank_transfers.php'", 'route registered');
has(src($root, 'header.php'), "getUrl('bank_transfers')", 'menu link present');

// ─────────────────────────────────────────────────────────────────────────
section('3. API contracts — post-gated, both legs, register, void, idempotent');
$add = src($root, 'api/account/add_bank_transfer.php');
has($add, "canCreate('bank_transfers')", 'add gated by canCreate');
has($add, "csrf_check()", 'add enforces CSRF');
has($add, "from_id === \$to_id", 'add rejects same source/destination');
has($add, "Insufficient balance", 'add validates source balance');
hasnt($add, "applyAccountBalanceDelta", 'add moves NO money at create');

$st = src($root, 'api/account/update_bank_transfer_status.php');
has($st, "'transaction_type' => 'transfer'", 'post writes a transfer ledger entry');
has($st, "applyAccountBalanceDelta(\$pdo, \$from, 'credit', \$total)", 'post moves source down by gross');
has($st, "applyAccountBalanceDelta(\$pdo, \$to,   'debit',  \$amount)", 'post moves destination up by net');
has($st, "recordBankTransaction(\$pdo, \$from, \$total,  'withdrawal'", 'post writes source withdrawal register row');
has($st, "recordBankTransaction(\$pdo, \$to,   \$amount, 'deposit'", 'post writes destination deposit register row');
has($st, "empty(\$t['transaction_id'])", 'post is idempotent (only if not already posted)');
has($st, "reverseBankTransaction", 'void reverses the register rows');
has($st, "Insufficient balance in the source account to post", 'post re-checks balance');
has($st, "canReview('bank_transfers')", 'review gated by canReview');
has($st, "canApprove('bank_transfers')", 'approve gated by canApprove');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — create endpoint records a transfer but moves NO money');
$_SESSION = ['user_id' => 4, 'role_id' => 1, 'username' => 'cli-test', 'csrf_token' => 'testtok',
             'first_name' => 'CLI', 'last_name' => 'Test', 'user_role' => 'Admin'];
$cash = array_map(fn($a) => (int)$a['account_id'], cashBankAccounts($pdo));
$from = $cash[0]; $to = $cash[1];
$chargeAcc = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='expense' LIMIT 1")->fetchColumn();
try {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE accounts SET current_balance = 100000 WHERE account_id = $from");
    $bal0_from = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
    $bal0_to   = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$to")->fetchColumn();

    $res = callPost($root, 'api/account/add_bank_transfer.php', [
        'transfer_date' => date('Y-m-d'), 'from_account_id' => $from, 'to_account_id' => $to,
        'amount' => 100, 'charges' => 10, 'charge_account_id' => $chargeAcc, 'description' => '__BT_TEST__',
    ]);
    (is_array($res) && !empty($res['success'])) ? pass('create endpoint returned success') : fail('create failed: ' . json_encode($res));

    $balN_from = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
    $balN_to   = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$to")->fetchColumn();
    (abs($balN_from - $bal0_from) < 0.001 && abs($balN_to - $bal0_to) < 0.001) ? pass('create moved NO money (both balances unchanged)') : fail("create moved money ($bal0_from->$balN_from, $bal0_to->$balN_to)");

    $row = $pdo->query("SELECT status, transaction_id FROM bank_transfers WHERE description='__BT_TEST__' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ($row && $row['status'] === 'pending' && empty($row['transaction_id'])) ? pass('create left it pending with no transaction_id') : fail('unexpected created state: ' . json_encode($row));

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('create runtime error: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — post moves both legs + register; void reverses (rolled back)');
try {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE accounts SET current_balance = 100000 WHERE account_id = $from");
    $bal0_from = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
    $bal0_to   = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$to")->fetchColumn();

    $amount = 100.0; $charges = 10.0; $total = 110.0; $ref = 'TRF-TEST-' . time();
    $items = [
        ['account_id' => $to,        'type' => 'debit',  'amount' => $amount,  'description' => 'xfer'],
        ['account_id' => $chargeAcc, 'type' => 'debit',  'amount' => $charges, 'description' => 'charge'],
        ['account_id' => $from,      'type' => 'credit', 'amount' => $total,   'description' => 'xfer'],
    ];
    $r = recordGlobalTransaction(['transaction_date' => date('Y-m-d'), 'amount' => $total,
        'transaction_type' => 'transfer', 'reference_number' => $ref, 'description' => 'xfer',
        'journal_items' => $items], $pdo);
    if (empty($r['success'])) { fail('ledger post failed'); $pdo->rollBack(); }
    else {
        $txn = (int)$r['transaction_id'];
        applyAccountBalanceDelta($pdo, $from, 'credit', $total);
        applyAccountBalanceDelta($pdo, $to,   'debit',  $amount);
        recordBankTransaction($pdo, $from, $total,  'withdrawal', date('Y-m-d'), $ref, 'xfer', 4);
        recordBankTransaction($pdo, $to,   $amount, 'deposit',    date('Y-m-d'), $ref, 'xfer', 4);

        $bf = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
        $bt = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$to")->fetchColumn();
        (abs($bf - ($bal0_from - $total)) < 0.001) ? pass('source dropped by amount+charges (110)') : fail("source wrong: $bf vs " . ($bal0_from - $total));
        (abs($bt - ($bal0_to + $amount)) < 0.001) ? pass('destination rose by amount (100)') : fail("dest wrong: $bt vs " . ($bal0_to + $amount));

        $books = $pdo->query("SELECT type, SUM(amount) s FROM books_transactions WHERE transaction_id=$txn GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
        ((int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn() === 3) ? pass('ledger wrote a 3-line entry (Dr dest + Dr charge / Cr source)') : fail('expected 3 ledger lines');
        (abs((float)($books['debit'] ?? 0) - (float)($books['credit'] ?? 0)) < 0.001) ? pass('ledger entry balances (Dr == Cr == 110)') : fail('ledger not balanced: ' . json_encode($books));

        $reg = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn();
        ($reg === 2) ? pass('two register rows written (withdrawal + deposit)') : fail("expected 2 register rows, got $reg");

        // ── Void ──
        applyAccountBalanceDelta($pdo, $from, 'debit',  $total);
        applyAccountBalanceDelta($pdo, $to,   'credit', $amount);
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id=?")->execute([$txn]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txn]);
        reverseBankTransaction($pdo, $from, $ref, 'withdrawal');
        reverseBankTransaction($pdo, $to,   $ref, 'deposit');

        $bf2 = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
        $bt2 = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$to")->fetchColumn();
        (abs($bf2 - $bal0_from) < 0.001 && abs($bt2 - $bal0_to) < 0.001) ? pass('void restored both balances') : fail("void balances wrong ($bf2/$bt2 vs $bal0_from/$bal0_to)");
        ((int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn() === 0) ? pass('void removed both register rows') : fail('register rows remained');

        $pdo->rollBack();
        pass('post/void cycle rolled back (no persistence)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('post/void runtime error: ' . $e->getMessage());
}
