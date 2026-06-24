<?php
/**
 * Journal edit immutability guard — CLI test
 *   php tests/test_journal_update_immutability_cli.php
 *
 * Gap (account_financial.md #15): update_journal.php had NO immutability guard — it
 * would edit a POSTED journal entry in place (replace all items + re-sync the mirror),
 * silently rewriting history that is already in the reports. delete_journal.php blocks
 * posted; the edit path did not. This verifies the fix wires assertJournalNotPosted()
 * so a posted entry is blocked while a draft stays editable.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ledger_post.php";   // assertJournalNotPosted / LedgerException
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 4; $_SESSION['username'] = 'cli'; $_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

// ── 1. Source contract ───────────────────────────────────────────────────────
section('1. update_journal.php — immutability guard wired');
$src = file_get_contents("$root/api/account/update_journal.php");
ok(strpos($src, 'core/ledger_post.php') !== false, 'includes core/ledger_post.php');
ok(strpos($src, 'assertJournalNotPosted($pdo') !== false, 'guards edit with assertJournalNotPosted()');
ok(strpos($src, 'beginTransaction') !== false && strpos($src, 'assertJournalNotPosted') < strpos($src, 'beginTransaction'), 'guard runs before the write begins');

// ── 2. Runtime — posted blocked, draft allowed (rolled back) ────────────────
section('2. Runtime — posted blocked, draft editable');
$acc = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1")->fetchColumn();
ok($acc > 0, "have an active account (#$acc) for the synthetic entries");

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO journal_entries
        (entry_date, reference_number, description, status, created_by, debit_account_id, credit_account_id, amount, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $ins->execute([date('Y-m-d'), 'JRNL-IMMUT-' . uniqid(), 'immutability test (posted)', 'posted', 4, $acc, $acc, 100.00]);
    $postedId = (int)$pdo->lastInsertId();
    $threw = false;
    try { assertJournalNotPosted($pdo, $postedId); } catch (LedgerException $e) { $threw = true; }
    ok($threw, 'assertJournalNotPosted THROWS for a posted entry (edit blocked)');

    $ins->execute([date('Y-m-d'), 'JRNL-IMMUT-' . uniqid(), 'immutability test (draft)', 'draft', 4, $acc, $acc, 100.00]);
    $draftId = (int)$pdo->lastInsertId();
    $okDraft = true;
    try { assertJournalNotPosted($pdo, $draftId); } catch (LedgerException $e) { $okDraft = false; }
    ok($okDraft, 'assertJournalNotPosted ALLOWS a draft entry (still editable)');
} finally {
    $pdo->rollBack();
}
$leak = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_number LIKE 'JRNL-IMMUT-%'")->fetchColumn();
ok($leak === 0, 'rolled back cleanly — no test rows persisted');
