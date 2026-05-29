<?php
/**
 * Phase 0.4 — assertJournalNotPosted immutability guard CLI test
 * ---------------------------------------------------------------
 *   php tests/test_phase0_immutability_guard_cli.php
 *
 * Verifies the guard correctly throws LedgerException for every immutable
 * state and silently returns only for 'draft'. Uses a transaction +
 * rollback wrapper around the synthetic test rows so the live DB is
 * unchanged at the end.
 *
 * Test rows are created with raw INSERT (not postLedgerEntry) so we can
 * pick the status — postLedgerEntry always writes 'posted'.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ledger_post.php";

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
section('1. Function present + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
function_exists('assertJournalNotPosted')
    ? pass('assertJournalNotPosted() defined')
    : fail('function missing');

$rc = 0;
exec("php -l " . escapeshellarg("$root/core/ledger_post.php") . " 2>&1", $o, $rc);
$rc === 0 ? pass('core/ledger_post.php lint-clean') : fail('lint failed');

// Find a real posted entry (id=1 or 2 from earlier seed)
$posted_entry_id = (int)$pdo->query(
    "SELECT entry_id FROM journal_entries WHERE status = 'posted' ORDER BY entry_id LIMIT 1"
)->fetchColumn();
$account_a = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1")->fetchColumn();
$account_b = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 1 OFFSET 1")->fetchColumn();

if (!$posted_entry_id || !$account_a || !$account_b) {
    fail('cannot run — need at least one posted journal_entries row + 2 active accounts');
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Existing posted entry — guard throws');
// ─────────────────────────────────────────────────────────────────────────
try {
    assertJournalNotPosted($pdo, $posted_entry_id);
    fail("posted entry id=$posted_entry_id was NOT rejected — guard failed");
} catch (LedgerException $e) {
    if (stripos($e->getMessage(), 'posted and immutable') !== false) {
        pass("posted entry id=$posted_entry_id correctly throws with 'posted and immutable' message");
    } else {
        fail("posted entry threw but message lacks 'posted and immutable': " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Non-existent entry_id — guard throws');
// ─────────────────────────────────────────────────────────────────────────
try {
    assertJournalNotPosted($pdo, 999999);
    fail("missing entry_id=999999 should have thrown");
} catch (LedgerException $e) {
    stripos($e->getMessage(), 'not found') !== false
        ? pass("missing entry_id throws with 'not found' message")
        : fail("missing entry_id threw but message lacks 'not found': " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Invalid entry_id <= 0 — guard throws');
// ─────────────────────────────────────────────────────────────────────────
foreach ([0, -1, -999] as $bad_id) {
    try {
        assertJournalNotPosted($pdo, $bad_id);
        fail("entry_id=$bad_id should have thrown");
    } catch (LedgerException $e) {
        stripos($e->getMessage(), 'invalid entry_id') !== false
            ? pass("entry_id=$bad_id correctly rejected (invalid entry_id)")
            : fail("entry_id=$bad_id threw with wrong message: " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Status-state matrix — using synthetic rows (rolled back)');
// ─────────────────────────────────────────────────────────────────────────
// We create one synthetic row per status, run the guard, assert behaviour,
// then rollback so the DB ends up unchanged. Each status uses its own
// nested transaction (savepoint) so a single failure doesn't abort others.

$states = [
    ['draft',    false, 'returns silently — drafts are editable'],
    ['posted',   true,  'throws — posted is immutable'],
    ['void',     true,  'throws — void is terminal'],
    ['reversed', true,  'throws — reversed is terminal'],
];
foreach ($states as [$status, $should_throw, $label]) {
    $pdo->beginTransaction();
    try {
        // Insert synthetic row with the target status
        $ins = $pdo->prepare("
            INSERT INTO journal_entries
              (entry_date, reference_number, description,
               debit_account_id, credit_account_id, amount, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ref = '__TEST__-' . strtoupper($status) . '-' . random_int(1000, 9999);
        $ins->execute(['2026-05-28', $ref, "test $status row", $account_a, $account_b, 1.00, $status, 4]);
        $test_id = (int)$pdo->lastInsertId();

        // Sanity: did we actually create with the requested status?
        $check = $pdo->prepare("SELECT status FROM journal_entries WHERE entry_id = ?");
        $check->execute([$test_id]);
        $actual = $check->fetchColumn();
        if ($actual !== $status) {
            fail("setup error: requested status='$status' but got '$actual'");
            $pdo->rollBack(); continue;
        }

        try {
            assertJournalNotPosted($pdo, $test_id);
            if ($should_throw) {
                fail("status='$status' should have thrown — $label");
            } else {
                pass("status='$status' → $label");
            }
        } catch (LedgerException $e) {
            if ($should_throw) {
                pass("status='$status' → $label  (msg: " . substr($e->getMessage(), 0, 50) . "…)");
            } else {
                fail("status='$status' should NOT have thrown but did: " . $e->getMessage());
            }
        }

        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail("status='$status' test setup failed: " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Caller can catch LedgerException specifically');
// ─────────────────────────────────────────────────────────────────────────
// Ensure the exception class hierarchy is what callers expect:
// LedgerException extends RuntimeException extends Exception.
try {
    assertJournalNotPosted($pdo, $posted_entry_id);
} catch (LedgerException $e) {
    pass('caught as LedgerException');
    $e instanceof RuntimeException ? pass('also instanceof RuntimeException') : fail('not RuntimeException');
    $e instanceof Exception ? pass('also instanceof Exception') : fail('not Exception');
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Live DB unchanged after test run');
// ─────────────────────────────────────────────────────────────────────────
$count_after = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$test_rows   = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE reference_number LIKE '__TEST__-%'")->fetchColumn();
$test_rows === 0 ? pass('no orphan synthetic rows left behind (all rolled back)') : fail("$test_rows orphan __TEST__- rows remain");

exit($failures === 0 ? 0 : 1);
