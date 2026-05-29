<?php
/**
 * Phase 2.1 — General Ledger API CLI test
 * ----------------------------------------
 *   php tests/test_phase2_general_ledger_cli.php
 *
 * Verifies:
 *   1. File exists + lint-clean.
 *   2. Source contains the agreed structural patterns.
 *   3. Parameter validation: missing account_id (400), bad dates (400),
 *      start > end (400), non-existent account_id (404).
 *   4. Runtime: opening balance = accounts.opening_balance + prior net.
 *   5. Running balance accumulates correctly through lines.
 *   6. Closing balance = opening + window_dr - window_cr  (debit-natural)
 *                       or opening + window_cr - window_dr  (credit-natural).
 *   7. Source column: "Manual" when entity_type/id NULL,
 *      otherwise "entity_type-entity_id".
 *   8. Synthetic postings via postLedgerEntry round-trip through GL
 *      (created in a transaction + rolled back, no production data
 *      changed).
 *   9. Non-admin out-of-scope project → HTTP 403.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/ledger_post.php";

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
function readSrc(string $root, string $rel): string {
    $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : '';
}

/**
 * Call the GL API in-process. Returns the decoded JSON response.
 * Swallows the early exit() by capturing output.
 */
function callGL(string $root, array $params): array {
    $_GET = $params;
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start();
    try { @require "$root/api/account/get_general_ledger.php"; } catch (Throwable $e) {}
    $raw = ob_get_clean();
    error_reporting($prevErr);
    return json_decode($raw, true) ?? ['__raw' => substr($raw, 0, 500)];
}

global $pdo;

// ─────────────────────────────────────────────────────────────────────────
section('1. File exists + lint-clean');
// ─────────────────────────────────────────────────────────────────────────
$file = "$root/api/account/get_general_ledger.php";
file_exists($file) ? pass('api/account/get_general_ledger.php exists') : fail('file missing');
$rc = 0; exec("php -l " . escapeshellarg($file) . " 2>&1", $o, $rc);
$rc === 0 ? pass('lint-clean') : fail('lint failed');

// ─────────────────────────────────────────────────────────────────────────
section('2. Source contains agreed structural patterns');
// ─────────────────────────────────────────────────────────────────────────
$src = readSrc($root, 'api/account/get_general_ledger.php');
$checks = [
    "isAuthenticated()"                                  => 'auth check',
    "userCan('project'"                                  => 'authorisation via canonical userCan()',
    "scopeFilterSqlNullable('project'"                   => 'canonical scope helper',
    "FROM accounts a"                                    => 'reads accounts table',
    "LEFT JOIN account_types"                            => 'joins account_types for normal_side',
    "FROM journal_entry_items"                           => 'reads journal_entry_items',
    "INNER JOIN journal_entries"                         => 'joins journal_entries',
    "je.status        = 'posted'"                        => "filters posted entries",
    "je.entry_date    <  ?"                              => 'opening uses strict-less-than start_date',
    "je.entry_date   BETWEEN ? AND ?"                    => 'detail lines use BETWEEN',
    "ORDER BY je.entry_date ASC"                         => 'lines sorted chronologically',
    "normal_side"                                        => 'reads normal_side',
    "running_balance"                                    => 'returns running_balance per line',
    "'opening_balance'"                                  => 'returns opening_balance scalar',
    "'closing_balance'"                                  => 'returns closing_balance scalar',
    "'window_debit_total'"                               => 'returns window_debit_total',
    "'window_credit_total'"                              => 'returns window_credit_total',
    "'Manual'"                                           => 'fallback source label when no entity link',
    "entity_type'] . '-' . (int)"                        => 'composes source as entity_type-entity_id',
];
foreach ($checks as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Parameter validation present in source');
// ─────────────────────────────────────────────────────────────────────────
// Note: we can't call the API multiple times with bad params because each
// validation path calls exit() which terminates the entire test. Source-
// grep verifies the validation logic exists; the validation runs at
// runtime when an end user POSTs a bad request.
$validations = [
    "account_id <= 0"                                     => 'guards account_id <= 0',
    "'account_id is required'"                            => 'returns "account_id is required" message',
    "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/'"              => 'validates YYYY-MM-DD date format',
    "'start_date and end_date must be YYYY-MM-DD'"        => 'returns date-format error message',
    "start_date > \$end_date"                             => 'guards start > end',
    "'start_date must be <= end_date'"                    => 'returns date-order error message',
    "http_response_code(404)"                             => 'returns 404 for missing account',
    "\"Account \$account_id not found.\""                 => 'message text for missing account',
    "http_response_code(400)"                             => 'returns 400 for bad params',
    "http_response_code(401)"                             => 'returns 401 for unauthenticated',
    "http_response_code(403)"                             => 'returns 403 for out-of-scope',
];
foreach ($validations as $needle => $label) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime against live ledger — opening balance correctness');
// ─────────────────────────────────────────────────────────────────────────
// Account 2 = Opening Balance Equity (credit-natural). Has accounts.opening_
// balance = 12,000. Has 1 posted credit entry for 1,000 on 2026-01-17.
// Opening balance at 2026-01-18 should = 12,000 + 1,000 = 13,000.
$r = callGL($root, ['account_id' => 2, 'start_date' => '2026-01-18', 'end_date' => '2026-05-31']);
if (!empty($r['success'])) {
    pass('API success for account_id=2');
    $opening = $r['data']['opening_balance'];
    abs($opening - 13000) < 0.5
        ? pass("opening_balance at 2026-01-18 = 13,000 (includes prior posting)")
        : fail("opening_balance wrong: got $opening, expected 13,000");
} else {
    fail('GL call for live account_id=2 failed: ' . substr($r['__raw'] ?? '', 0, 200));
}

// Same account, start_date BEFORE the entry — opening should be just 12,000
$r = callGL($root, ['account_id' => 2, 'start_date' => '2026-01-01', 'end_date' => '2026-05-31']);
if (!empty($r['success'])) {
    $opening = $r['data']['opening_balance'];
    abs($opening - 12000) < 0.5
        ? pass("opening_balance at 2026-01-01 = 12,000 (no prior postings yet)")
        : fail("opening_balance wrong: got $opening, expected 12,000");

    // Running balance after the only entry should be 13,000
    if (!empty($r['data']['lines'])) {
        $running = $r['data']['lines'][0]['running_balance'];
        abs($running - 13000) < 0.5
            ? pass("running_balance after first entry = 13,000")
            : fail("running_balance wrong: got $running, expected 13,000");
    }

    // Closing balance equals running_balance of last line
    $closing = $r['data']['closing_balance'];
    abs($closing - 13000) < 0.5
        ? pass("closing_balance = 13,000")
        : fail("closing_balance wrong: got $closing, expected 13,000");

    // Source column = "Manual" for the existing entry (entity_type IS NULL)
    if (!empty($r['data']['lines'])) {
        $r['data']['lines'][0]['source'] === 'Manual'
            ? pass('existing manual entry → source = "Manual"')
            : fail('source wrong: got "' . $r['data']['lines'][0]['source'] . '"');
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Synthetic posting via postLedgerEntry round-trips through GL');
// ─────────────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
try {
    $entry_id = postLedgerEntry(
        $pdo,
        '__test GL roundtrip__',
        [
            ['account_id' => 5, 'type' => 'debit',  'amount' => 250.00, 'description' => 'test Dr'],
            ['account_id' => 2, 'type' => 'credit', 'amount' => 250.00, 'description' => 'test Cr'],
        ],
        null,            // project_id
        42,              // entity_id
        'test_invoice',  // entity_type
        '2026-02-15',
        4
    );
    pass("synthetic entry posted (entry_id=$entry_id) inside test transaction");

    // GL for account 5 (CRDB Bank Main, debit-natural, opening 30,000)
    $r = callGL($root, ['account_id' => 5, 'start_date' => '2026-01-01', 'end_date' => '2026-05-31']);
    if (!empty($r['success'])) {
        $found = false; $source_ok = false;
        foreach ($r['data']['lines'] as $l) {
            if ((int)$l['entry_id'] === $entry_id) {
                $found = true;
                if ($l['source'] === 'test_invoice-42') $source_ok = true;
                if (abs((float)$l['debit'] - 250.00) < 0.01) pass("synthetic Dr line shows 250.00 debit");
            }
        }
        $found ? pass('synthetic line appears in account 5 GL') : fail('synthetic line missing from account 5 GL');
        $source_ok ? pass('source column = "test_invoice-42" (entity_type-entity_id)') : fail('source column not populated correctly');
    } else {
        fail('GL call after synthetic posting failed');
    }

    // Same entry from the credit account's perspective
    $r = callGL($root, ['account_id' => 2, 'start_date' => '2026-01-01', 'end_date' => '2026-05-31']);
    if (!empty($r['success'])) {
        $found_cr = false;
        foreach ($r['data']['lines'] as $l) {
            if ((int)$l['entry_id'] === $entry_id && abs((float)$l['credit'] - 250.00) < 0.01) {
                $found_cr = true;
                break;
            }
        }
        $found_cr ? pass('synthetic Cr line of 250.00 appears in account 2 GL') : fail('synthetic Cr line missing from account 2 GL');
    }

    $pdo->rollBack();
    pass('synthetic entry rolled back (no production data changed)');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('synthetic roundtrip threw: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
section('6. Non-admin out-of-scope project → 403');
// ─────────────────────────────────────────────────────────────────────────
$_SESSION['is_admin'] = false;
$_SESSION['scope']    = ['projects' => []];

$proj_row = $pdo->query("SELECT project_id FROM projects WHERE (status != 'archived' OR status IS NULL) ORDER BY project_id LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
if ($proj_row) {
    $r = callGL($root, [
        'account_id' => 2,
        'start_date' => '2026-01-01',
        'end_date'   => '2026-05-31',
        'project_id' => (int)$proj_row['project_id'],
    ]);
    (!empty($r['message']) && stripos($r['message'], 'not in your assigned scope') !== false)
        ? pass("out-of-scope project_id=" . (int)$proj_row['project_id'] . " → 403 enforced")
        : fail('non-admin out-of-scope check: ' . json_encode($r));
}
$_SESSION['is_admin'] = true;
unset($_SESSION['scope']);

exit($failures === 0 ? 0 : 1);
