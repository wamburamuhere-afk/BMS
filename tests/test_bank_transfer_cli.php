<?php
/**
 * Bank / Cash Transfer — auto-post + reverse — CLI test
 *   php tests/test_bank_transfer_cli.php
 *
 * The transfer workflow was removed: a transfer now AUTO-POSTS on creation
 * (add_bank_transfer.php) and is undone with a single Reverse action
 * (update_bank_transfer_status.php). This verifies:
 *   - files lint; migration applied ('reversed' status; 'transfer' txn type);
 *   - create moves BOTH cash legs, writes a balanced ledger entry + journal
 *     mirror + two register rows, and marks the transfer 'posted';
 *   - reverse restores both balances, REMOVES the journal mirror, deletes the
 *     ledger rows + register rows, and marks the transfer 'reversed';
 *   - the page offers View + Reverse only (no reviewed/approve/post/reject).
 * Runtime uses real commits, then tears its own rows down.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";          // cashBankAccounts
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
    $_POST['_csrf'] = $_SESSION['csrf_token'] ?? 'testtok';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'] ?? 'testtok';
    ob_start();
    include "$root/$rel";
    return json_decode(ob_get_clean(), true);
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
$files = [
    'migrations/2026_06_23_bank_transfer_reversed_status.php',
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
section('2. Migration applied — reversed status + transfer txn type');
$col = $pdo->query("SHOW COLUMNS FROM bank_transfers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
(strpos($col['Type'], "'reversed'") !== false) ? pass("'reversed' present in bank_transfers.status") : fail("'reversed' missing — run the migration");
$enum = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
(strpos($enum['Type'], "'transfer'") !== false) ? pass("'transfer' present in transactions.transaction_type") : fail("'transfer' missing");

// ─────────────────────────────────────────────────────────────────────────
section('3. Source contracts — create auto-posts, reverse unmirrors, no workflow');
$add = src($root, 'api/account/add_bank_transfer.php');
has($add, "canCreate('bank_transfers')", 'add gated by canCreate');
has($add, "csrf_check()", 'add enforces CSRF');
has($add, "'posted'", 'add inserts the transfer as posted');
has($add, "recordGlobalTransaction(", 'add posts the balanced ledger entry');
has($add, "applyAccountBalanceDelta(\$pdo, \$from_id, 'credit', \$total)", 'add moves source down by gross');
has($add, "applyAccountBalanceDelta(\$pdo, \$to_id,   'debit',  \$amount)", 'add moves destination up by net');
has($add, "recordBankTransaction", 'add writes the register rows');
has($add, "funds_warning", 'add still surfaces a funds warning (warn but allow)');

$st = src($root, 'api/account/update_bank_transfer_status.php');
has($st, "unmirrorTransactionFromJournal(\$pdo, \$txnId)", 'reverse REMOVES the journal mirror (the gap fix)');
has($st, "status = 'reversed'", 'reverse marks the transfer reversed');
has($st, "reverseBankTransaction", 'reverse undoes the register rows');
hasnt($st, "canReview('bank_transfers')", 'reverse no longer has a review gate (workflow removed)');
hasnt($st, "canApprove('bank_transfers')", 'reverse no longer has an approve gate (workflow removed)');

$page = src($root, 'app/constant/accounts/bank_transfers.php');
has($page, "reverseTransfer(", 'page offers the Reverse action');
has($page, "Prepared by", 'page View shows "Prepared by" (the creator)');
hasnt($page, "changeStatus(", 'page no longer has the workflow changeStatus action');
hasnt($page, "Mark Reviewed", 'page no longer shows Mark Reviewed');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — create auto-posts; reverse unwinds (real commit + teardown)');
$_SESSION = ['user_id' => 4, 'role_id' => 1, 'username' => 'cli-test', 'csrf_token' => 'testtok',
             'first_name' => 'CLI', 'last_name' => 'Test', 'user_role' => 'Admin', 'is_admin' => true];
$cash = array_values(array_map(fn($a) => (int)$a['account_id'], cashBankAccounts($pdo)));
if (count($cash) < 2) { fail('need 2 cash/bank accounts to test'); return; }
$from = $cash[0]; $to = $cash[1];
$chargeAcc = (int)$pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                                WHERE a.status='active' AND at.category IN ('expense','finance_cost') LIMIT 1")->fetchColumn();
$DESC = '__BT_AUTOPOST_TEST__';
$amount = 100.0; $charges = 10.0; $total = 110.0;

$createdId = 0; $ref = '';
$bal0_from = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$from")->fetchColumn();
$bal0_to   = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id=$to")->fetchColumn();

try {
    $res = callPost($root, 'api/account/add_bank_transfer.php', [
        'transfer_date' => date('Y-m-d'), 'from_account_id' => $from, 'to_account_id' => $to,
        'amount' => $amount, 'charges' => $charges, 'charge_account_id' => $chargeAcc, 'description' => $DESC,
    ]);
    (is_array($res) && !empty($res['success'])) ? pass('create endpoint returned success') : fail('create failed: ' . json_encode($res));
    $createdId = (int)($res['id'] ?? 0);
    $ref = (string)($res['transfer_number'] ?? '');

    $row = $pdo->query("SELECT status, transaction_id, posted_by FROM bank_transfers WHERE id=$createdId")->fetch(PDO::FETCH_ASSOC);
    ($row && $row['status'] === 'posted') ? pass('transfer created as POSTED') : fail('not posted: ' . json_encode($row));
    (!empty($row['transaction_id'])) ? pass('transaction_id set on the transfer') : fail('no transaction_id');
    ((int)$row['posted_by'] === 4) ? pass('posted_by = the creator') : fail('posted_by not the creator');
    $txn = (int)$row['transaction_id'];

    // balances moved
    $bf = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
    $bt = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$to")->fetchColumn();
    (abs($bf - ($bal0_from - $total)) < 0.001) ? pass('source dropped by amount+charges (110)') : fail("source wrong: $bf vs " . ($bal0_from - $total));
    (abs($bt - ($bal0_to + $amount)) < 0.001) ? pass('destination rose by amount (100)') : fail("dest wrong: $bt vs " . ($bal0_to + $amount));

    // ledger + mirror + register
    $nbooks = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn();
    ($nbooks === 3) ? pass('ledger wrote a 3-line entry (Dr dest + Dr charge / Cr source)') : fail("expected 3 ledger lines, got $nbooks");
    $mirror = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=$txn AND status='posted'")->fetchColumn();
    ($mirror === 1) ? pass('journal_entries mirror created (reports see it)') : fail('journal mirror missing');
    $reg = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn();
    ($reg === 2) ? pass('two register rows written (withdrawal + deposit)') : fail("expected 2 register rows, got $reg");

    // ── Reverse ──
    $rev = callPost($root, 'api/account/update_bank_transfer_status.php', ['id' => $createdId, 'status' => 'reversed']);
    (is_array($rev) && !empty($rev['success'])) ? pass('reverse endpoint returned success') : fail('reverse failed: ' . json_encode($rev));

    $row2 = $pdo->query("SELECT status, transaction_id FROM bank_transfers WHERE id=$createdId")->fetch(PDO::FETCH_ASSOC);
    ($row2['status'] === 'reversed') ? pass('transfer marked reversed') : fail('status not reversed: ' . json_encode($row2));
    (empty($row2['transaction_id'])) ? pass('transaction_id cleared on reverse') : fail('transaction_id not cleared');

    $bf2 = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$from")->fetchColumn();
    $bt2 = (float)$pdo->query("SELECT current_balance FROM accounts WHERE account_id=$to")->fetchColumn();
    (abs($bf2 - $bal0_from) < 0.001 && abs($bt2 - $bal0_to) < 0.001) ? pass('reverse restored both balances') : fail("reverse balances wrong ($bf2/$bt2 vs $bal0_from/$bal0_to)");

    $mirror2 = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=$txn")->fetchColumn();
    ($mirror2 === 0) ? pass('reverse REMOVED the journal mirror (gone from reports)') : fail('journal mirror still present after reverse');
    $books2 = (int)$pdo->query("SELECT COUNT(*) FROM books_transactions WHERE transaction_id=$txn")->fetchColumn();
    ($books2 === 0) ? pass('reverse removed the legacy ledger rows') : fail('books_transactions remained');
    $reg2 = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number=" . $pdo->quote($ref))->fetchColumn();
    ($reg2 === 0) ? pass('reverse removed both register rows') : fail("register rows remained ($reg2)");

} catch (Throwable $e) {
    fail('runtime error: ' . $e->getMessage());
} finally {
    // Teardown: remove the test transfer row (its ledger/register/mirror are already gone after reverse).
    if ($createdId) {
        $pdo->prepare("DELETE FROM bank_transfers WHERE id = ?")->execute([$createdId]);
    }
    // Safety: if the test aborted before reverse, scrub any residue by ref.
    if ($ref) {
        $tx = (int)$pdo->query("SELECT transaction_id FROM transactions WHERE reference_number=" . $pdo->quote($ref) . " LIMIT 1")->fetchColumn();
        if ($tx) {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id IN (SELECT entry_id FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=?)")->execute([$tx]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=?")->execute([$tx]);
            $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id=?")->execute([$tx]);
            $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$tx]);
        }
        $pdo->prepare("DELETE FROM bank_transactions WHERE reference_number=?")->execute([$ref]);
    }
}
