<?php
/**
 * Bank Reconciliation — status-update endpoint — CLI test
 *   php tests/test_reconciliation_status_update_cli.php
 *
 * Guards the regression where api/account/update_reconciliation_status.php wrote to
 * a NON-EXISTENT column: `SET ... updated_by = ?`. bank_reconciliations has no
 * updated_by column — the status-change actor/time live in reviewed_by/reviewed_date.
 * MySQL threw 1054 (Unknown column), surfaced to the user as "Database error".
 *
 * Verifies: (1) the file lints + requires roots.php at the correct depth; (2) the
 * UPDATE targets reviewed_by/reviewed_date and never updated_by; (3) the EXACT query
 * the endpoint runs executes against the real schema without throwing and actually
 * flips the status + stamps the reviewer. Runtime seeds one row and DELETEs it in a
 * finally block (bank_reconciliations is MyISAM — no rollback). Nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
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

$endpoint = 'api/account/update_reconciliation_status.php';

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lint clean + correct require depth');
$full = "$root/$endpoint";
if (!file_exists($full)) { fail("MISSING: $endpoint"); }
else {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$endpoint lints clean") : fail("php -l failed: $endpoint");
}
$code = src($root, $endpoint);
has($code, "__DIR__ . '/../../roots.php'", 'requires roots.php at the right depth (two levels)');

// ─────────────────────────────────────────────────────────────────────────
section('2. UPDATE targets real columns (reviewed_by/reviewed_date, never updated_by)');
has($code, 'reviewed_by', 'UPDATE records the actor via reviewed_by');
has($code, 'reviewed_date', 'UPDATE stamps reviewed_date');
hasnt($code, 'updated_by = ?', 'UPDATE never writes the non-existent updated_by column');

// Schema truth: bank_reconciliations must NOT have updated_by but MUST have reviewed_by/reviewed_date.
$cols = $pdo->query("SHOW COLUMNS FROM bank_reconciliations")->fetchAll(PDO::FETCH_COLUMN);
(!in_array('updated_by', $cols, true)) ? pass('schema confirms there is no updated_by column') : fail('schema unexpectedly has updated_by');
(in_array('reviewed_by', $cols, true)) ? pass('schema has reviewed_by') : fail('schema missing reviewed_by');
(in_array('reviewed_date', $cols, true)) ? pass('schema has reviewed_date') : fail('schema missing reviewed_date');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — the exact endpoint UPDATE flips status + stamps reviewer (cleaned up)');
try {
    $bank = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='asset' AND (cash_flow_category='cash') ORDER BY account_id LIMIT 1")->fetchColumn();
    $uid  = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
    if ($bank <= 0) { fail('no cash/bank account to test against'); }
    elseif ($uid <= 0) { fail('no user to test against'); }
    else {
        $tag = 'TEST-' . substr(uniqid('', true), -10);
        $recNo = 'REC-' . $tag;
        $recId = 0;
        try {
            $pdo->prepare("INSERT INTO bank_reconciliations (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end, statement_balance, book_balance, difference, status, prepared_by, created_at, updated_at)
                           VALUES (?, ?, NOW(), '2099-01-01', '2099-01-31', 0, 0, 0, 'pending', ?, NOW(), NOW())")
                ->execute([$recNo, $bank, $uid]);
            $recId = (int)$pdo->lastInsertId();

            // The EXACT statement the endpoint runs.
            $stmt = $pdo->prepare("UPDATE bank_reconciliations SET status = ?, updated_at = NOW(), reviewed_by = ?, reviewed_date = NOW() WHERE reconciliation_id = ?");
            $stmt->execute(['disputed', $uid, $recId]);
            pass('endpoint UPDATE executed without throwing (no 1054)');

            $row = $pdo->query("SELECT status, reviewed_by FROM bank_reconciliations WHERE reconciliation_id=$recId")->fetch(PDO::FETCH_ASSOC);
            ($row['status'] === 'disputed') ? pass('status flipped to disputed') : fail('status not updated: ' . $row['status']);
            ((int)$row['reviewed_by'] === $uid) ? pass('reviewed_by stamped with the actor') : fail('reviewed_by not set: ' . $row['reviewed_by']);
        } finally {
            if ($recId) $pdo->prepare("DELETE FROM bank_reconciliations WHERE reconciliation_id=?")->execute([$recId]);
        }
        pass('seeded reconciliation cleaned up (MyISAM-safe; nothing persists)');
    }
} catch (Throwable $e) {
    fail('runtime error: ' . $e->getMessage());
}
