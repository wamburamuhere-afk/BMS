<?php
/**
 * Resilience fix — autoPostEvent survives missing journal_mappings table
 * -----------------------------------------------------------------------
 *   php tests/test_phase4_auto_post_resilience_cli.php
 *
 * Verifies:
 *   1. core/auto_post_hook.php lint-clean.
 *   2. Source contains the try/catch around the journal_mappings query,
 *      detects SQLSTATE 42S02 + "doesn't exist" + "no such table",
 *      returns 'infrastructure_missing' instead of throwing.
 *   3. Live-DB simulation: temporarily RENAME journal_mappings to a
 *      sentinel name, call autoPostEvent, assert it returns
 *      posted=false, reason='infrastructure_missing'. Restore the table
 *      in a `finally`-equivalent block so the live DB is always clean
 *      at exit (even on assertion failure).
 *   4. After restoration: autoPostEvent works normally again
 *      (returns mapping_inactive for the seeded but-not-activated row).
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/auto_post_hook.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

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

$file = "$root/core/auto_post_hook.php";

// ─────────────────────────────────────────────────────────────────────────
section('1. File lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('core/auto_post_hook.php lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains the resilience path');
// ─────────────────────────────────────────────────────────────────────────
$src = file_get_contents($file);
$checks = [
    "catch (PDOException \$e)"                       => 'try/catch wraps the mapping lookup',
    "'42S02'"                                        => 'detects SQLSTATE 42S02 (table not found)',
    "doesn't exist"                                  => 'detects "doesn\'t exist" in message',
    "no such table"                                  => 'detects "no such table" in message',
    "'infrastructure_missing'"                       => 'returns infrastructure_missing reason',
    'throw $e'                                       => 're-throws other DB errors (connection lost, etc.)',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Live simulation: missing table → infrastructure_missing');
// ─────────────────────────────────────────────────────────────────────────
//
// We temporarily rename journal_mappings → journal_mappings_resilience_test_tmp
// so autoPostEvent's SELECT will hit "table doesn't exist". Restoration is
// wrapped in a finally-equivalent block so the live DB is ALWAYS returned
// to a clean state even if any assertion fails or throws.
//
global $pdo;
$RENAMED = false;

try {
    // Sanity: original table is present BEFORE we touch anything.
    $present = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
    $present ? pass('pre-condition: journal_mappings table is present') : fail('pre-condition failed: table missing already');

    // Rename it out of the way.
    $pdo->exec("RENAME TABLE journal_mappings TO journal_mappings_resilience_test_tmp");
    $RENAMED = true;

    // Sanity: it's now missing.
    $gone = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
    !$gone ? pass('rename succeeded: journal_mappings is now missing') : fail('rename did not remove journal_mappings from SHOW TABLES');

    // Call autoPostEvent against the missing infrastructure.
    $r = autoPostEvent(
        $pdo,
        'invoice_approved',
        'invoice',
        90090001,
        100.0,
        null,
        '2026-05-29',
        4,
        'Resilience test'
    );

    ($r['posted'] === false && ($r['reason'] ?? '') === 'infrastructure_missing')
        ? pass("autoPostEvent returned posted=false, reason='infrastructure_missing' (correct, no throw)")
        : fail('expected infrastructure_missing, got: ' . json_encode($r));

    ($r['event_type'] ?? '') === 'invoice_approved'
        ? pass('returned event_type echoed back so caller can log it')
        : fail('event_type not echoed in response: ' . json_encode($r));

} catch (Throwable $e) {
    fail('autoPostEvent threw despite missing table: ' . $e->getMessage());
} finally {
    // ALWAYS restore the table, even if a check above failed or threw.
    if ($RENAMED) {
        try {
            $pdo->exec("RENAME TABLE journal_mappings_resilience_test_tmp TO journal_mappings");
            $restored = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
            $restored ? pass('cleanup: journal_mappings table restored')
                      : fail('cleanup: restoration appeared to succeed but SHOW TABLES disagrees');
        } catch (Throwable $e) {
            fail('cleanup: failed to restore journal_mappings — MANUAL INTERVENTION REQUIRED. ' . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('4. After restore: normal path still works');
// ─────────────────────────────────────────────────────────────────────────
//
// The seeded 'invoice_approved' row defaults to is_active=0. So after
// restoration, calling autoPostEvent should return mapping_inactive — proof
// that the resilience path didn't permanently change anything.
//
$r2 = autoPostEvent(
    $pdo,
    'invoice_approved',
    'invoice',
    90090002,
    100.0,
    null,
    '2026-05-29',
    4,
    'Post-restore test'
);
($r2['posted'] === false && ($r2['reason'] ?? '') === 'mapping_inactive')
    ? pass('post-restore: returns mapping_inactive (the seeded-but-OFF state)')
    : fail('post-restore: unexpected response: ' . json_encode($r2));

exit($failures === 0 ? 0 : 1);
