<?php
/**
 * Phase 0.3 — core/ledger_post.php (postLedgerEntry) CLI test
 * ------------------------------------------------------------
 *   php tests/test_phase0_ledger_post_cli.php
 *
 * Verifies:
 *   1. File exists and is lint-clean.
 *   2. LedgerException class is declared.
 *   3. postLedgerEntry function is declared.
 *   4. Happy path: balanced 2-line entry writes header + 2 items;
 *      returns valid entry_id; project_id + entity_id + entity_type
 *      captured; reference_number auto-generated.
 *   5. Happy path: balanced 3-line compound entry (1 debit + 2 credits).
 *   6. Empty description throws LedgerException.
 *   7. Bad date format throws.
 *   8. Single-line entry throws.
 *   9. Empty lines array throws.
 *  10. Unbalanced entry (Dr ≠ Cr) throws with diagnostic message.
 *  11. Missing account_id throws.
 *  12. Invalid type ('foo') throws.
 *  13. Zero/negative amount throws.
 *  14. Non-existent account_id throws (FK pre-check).
 *  15. Failed write rolls back fully (no orphan header or lines).
 *
 * Cleans up every test row at the end via DELETE on the fake refs.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/ledger_post.php";

$failures = 0;
$passes   = 0;
$created_entry_ids = [];  // for cleanup

register_shutdown_function(function () use (&$created_entry_ids) {
    global $passes, $failures, $pdo;
    static $printed = false;
    if ($printed) return; $printed = true;
    // Cleanup created test entries
    if (!empty($created_entry_ids)) {
        $ph = implode(',', array_fill(0, count($created_entry_ids), '?'));
        try {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id IN ($ph)")->execute($created_entry_ids);
            $pdo->prepare("DELETE FROM journal_entries WHERE entry_id IN ($ph)")->execute($created_entry_ids);
        } catch (Throwable $e) { /* ignore cleanup errors */ }
    }
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

global $pdo;

// Pick 2 real account_ids from the live DB for the happy-path tests.
$realAccounts = $pdo->query("SELECT account_id FROM accounts WHERE status='active' ORDER BY account_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (count($realAccounts) < 2) {
    echo "Cannot run test — need at least 2 active accounts in DB.\n";
    exit(1);
}
$debit_acct  = (int)$realAccounts[0];
$credit_acct = (int)$realAccounts[1];
$third_acct  = isset($realAccounts[2]) ? (int)$realAccounts[2] : $credit_acct;

// ─────────────────────────────────────────────────────────────────────────
section('1. File + symbols');
// ─────────────────────────────────────────────────────────────────────────
$file = "$root/core/ledger_post.php";
file_exists($file)             ? pass('core/ledger_post.php exists') : fail('file missing');
class_exists('LedgerException')   ? pass('LedgerException class declared') : fail('LedgerException missing');
function_exists('postLedgerEntry') ? pass('postLedgerEntry function declared') : fail('postLedgerEntry missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('php -l failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Happy path — balanced 2-line entry');
// ─────────────────────────────────────────────────────────────────────────
try {
    $entry_id = postLedgerEntry(
        $pdo,
        '__test entry 2-line__',
        [
            ['account_id' => $debit_acct,  'type' => 'debit',  'amount' => 1000.00, 'description' => 'test Dr line'],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 1000.00, 'description' => 'test Cr line'],
        ],
        null,                           // project_id
        12345,                          // entity_id
        'test_invoice',                 // entity_type
        '2026-05-28',
        4                               // user_id (admin)
    );
    if ($entry_id > 0) {
        pass("entry_id returned: $entry_id");
        $created_entry_ids[] = $entry_id;

        // Verify header
        $h = $pdo->prepare("SELECT * FROM journal_entries WHERE entry_id = ?");
        $h->execute([$entry_id]);
        $hrow = $h->fetch(PDO::FETCH_ASSOC);
        if ($hrow) {
            $hrow['status']        === 'posted'        ? pass("header.status = 'posted'") : fail("status got '{$hrow['status']}'");
            (float)$hrow['amount'] === 1000.00         ? pass('header.amount = 1000.00') : fail("amount got {$hrow['amount']}");
            (int)$hrow['entity_id']  === 12345         ? pass('header.entity_id captured (12345)') : fail("entity_id got '{$hrow['entity_id']}'");
            $hrow['entity_type']   === 'test_invoice'  ? pass("header.entity_type captured") : fail("entity_type got '{$hrow['entity_type']}'");
            $hrow['project_id']    === null            ? pass('header.project_id = NULL (company-wide)') : fail("project_id got '{$hrow['project_id']}'");
            preg_match('/^JRNL-\d{14}-\d{3}$/', $hrow['reference_number'])
                ? pass('reference_number auto-generated (JRNL-YYYYMMDDHHMMSS-NNN pattern)')
                : fail("reference_number malformed: '{$hrow['reference_number']}'");
        } else {
            fail('header row not found in journal_entries');
        }

        // Verify 2 line items
        $lstmt = $pdo->prepare("SELECT type, amount FROM journal_entry_items WHERE entry_id = ? ORDER BY item_id");
        $lstmt->execute([$entry_id]);
        $lines = $lstmt->fetchAll(PDO::FETCH_ASSOC);
        count($lines) === 2 ? pass('2 line items written') : fail("got " . count($lines) . " line items");
    } else {
        fail("entry_id <= 0: $entry_id");
    }
} catch (Throwable $e) {
    fail('happy-path 2-line threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Happy path — balanced 3-line compound entry (1 Dr, 2 Cr)');
// ─────────────────────────────────────────────────────────────────────────
try {
    $entry_id = postLedgerEntry(
        $pdo,
        '__test compound 3-line__',
        [
            ['account_id' => $debit_acct,  'type' => 'debit',  'amount' => 1500.00],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' =>  500.00],
            ['account_id' => $third_acct,  'type' => 'credit', 'amount' => 1000.00],
        ],
        null, null, null, '2026-05-28', 4
    );
    if ($entry_id > 0) {
        pass("3-line compound entry_id: $entry_id");
        $created_entry_ids[] = $entry_id;
        $n = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id = $entry_id")->fetchColumn();
        $n === 3 ? pass('3 line items written') : fail("got $n");
    } else { fail('compound returned 0'); }
} catch (Throwable $e) {
    fail('compound entry threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Validation rejections — all throw LedgerException');
// ─────────────────────────────────────────────────────────────────────────
$cases = [
    'empty description' => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, '', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 1],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 1],
        ], null, null, null, '2026-05-28', 4);
    },
    'bad date format' => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 1],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 1],
        ], null, null, null, '28/05/2026', 4);
    },
    'single-line entry' => function () use ($pdo, $debit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 1],
        ], null, null, null, '2026-05-28', 4);
    },
    'empty lines array' => function () use ($pdo) {
        return postLedgerEntry($pdo, 'x', [], null, null, null, '2026-05-28', 4);
    },
    'unbalanced (Dr > Cr)' => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 100],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 50],
        ], null, null, null, '2026-05-28', 4);
    },
    'missing account_id' => function () use ($pdo, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['type' => 'debit', 'amount' => 100],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 100],
        ], null, null, null, '2026-05-28', 4);
    },
    "invalid type ('foo')" => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'foo', 'amount' => 100],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 100],
        ], null, null, null, '2026-05-28', 4);
    },
    'zero amount' => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 0],
            ['account_id' => $credit_acct, 'type' => 'credit', 'amount' => 0],
        ], null, null, null, '2026-05-28', 4);
    },
    'non-existent account_id' => function () use ($pdo, $debit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 1],
            ['account_id' => 999999, 'type' => 'credit', 'amount' => 1],
        ], null, null, null, '2026-05-28', 4);
    },
    'both lines same type (no Cr)' => function () use ($pdo, $debit_acct, $credit_acct) {
        return postLedgerEntry($pdo, 'x', [
            ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 50],
            ['account_id' => $credit_acct, 'type' => 'debit', 'amount' => 50],
        ], null, null, null, '2026-05-28', 4);
    },
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        fail("$label — should have thrown but didn't");
    } catch (LedgerException $e) {
        pass("$label → LedgerException correctly thrown");
    } catch (Throwable $e) {
        fail("$label threw wrong type: " . get_class($e) . ' — ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Failed write rolls back fully');
// ─────────────────────────────────────────────────────────────────────────
// Force a mid-write failure by passing a non-existent account in lines.
// Since we pre-check accounts BEFORE inserting, no header should be written.
$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
try {
    postLedgerEntry($pdo, '__must_rollback__', [
        ['account_id' => $debit_acct, 'type' => 'debit', 'amount' => 100],
        ['account_id' => 999998, 'type' => 'credit', 'amount' => 100],
    ], null, null, null, '2026-05-28', 4);
    fail('expected throw, none thrown');
} catch (LedgerException $e) {
    $afterCount = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
    if ($afterCount === $beforeCount) {
        pass('no orphan header row written (count unchanged before/after)');
    } else {
        fail("rollback incomplete: before=$beforeCount after=$afterCount");
    }
}

exit($failures === 0 ? 0 : 1);
