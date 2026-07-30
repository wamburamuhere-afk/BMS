<?php
/**
 * tests/test_bank_recon_phase5_import_matching_cli.php
 * --------------------------------------------------------
 * Phase 5 — Statement Import + Two-Sided Matching. Verifies:
 *   1.  core/bank_recon_matching.php functions exist
 *   2.  php -l on every touched/new file
 *   3.  Import: rejects non-CSV extension
 *   4.  Import: rejects missing required columns
 *   5.  Import: creates statement-side rows (imported_from set)
 *   6.  Import: re-import with add_new is idempotent (no duplicate rows)
 *   7.  Import: update action updates description on existing row, no duplicate
 *   8.  Import: replace action removes the old unmatched row and inserts the new one (no data loss, no duplication)
 *   9.  Import: replace never touches a CONFIRMED matched row in the same date range
 *  10.  suggestBankRecMatches: pairs a book-side row with a same amount/type/date statement row
 *  11.  Suggested pairing does NOT flip matching_status (still 'unmatched' on both)
 *  12.  toggle_reconciliation_match 'match': ticking the STATEMENT side confirms BOTH sides
 *  13.  Both sides now share the same reconciliation_id
 *  14.  get_reconciliation_lines: reconciled_book math is correct with a confirmed pair (no double-count)
 *  15.  toggle_reconciliation_match 'unmatch': reverts both sides but keeps the suggestion pointer
 *  16.  toggle_reconciliation_match 'ignore': breaks the pairing (matched_transaction_id cleared both sides)
 *  17.  create_entry_from_statement_line: REJECTS a book-side line (imported_from IS NULL)
 *  18.  create_entry_from_statement_line: succeeds on a genuine unmatched statement-side line
 *  19.  create_entry_from_statement_line: posted line is auto-matched afterwards
 *
 * Bank_transactions/bank_reconciliations are MyISAM (no transactional
 * rollback) — every fixture row this file creates is deleted explicitly at
 * the end, same convention as test_bank_recon_phase1-4_cli.php.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/account_balance.php';
require_once __DIR__ . '/../core/bank_register.php';
require_once __DIR__ . '/../core/bank_recon_matching.php';
require_once __DIR__ . '/../core/ledger_post.php';
global $pdo;

$pass = 0; $fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label\n"; $pass++; }
    else        { echo "  FAIL: $label" . ($detail ? " — $detail" : '') . "\n"; $fail++; }
}

echo "\n=== Phase 5: Statement Import + Two-Sided Matching ===\n\n";

$_SESSION['user_id']  = 1;
$_SESSION['is_admin'] = true;

// ── Test 1: shared helper functions exist ─────────────────────────────────────
ok('suggestBankRecMatches() exists',   function_exists('suggestBankRecMatches'));
ok('confirmBankRecMatchPair() exists', function_exists('confirmBankRecMatchPair'));
ok('releaseBankRecMatchPair() exists', function_exists('releaseBankRecMatchPair'));
ok('breakBankRecMatchPair() exists',   function_exists('breakBankRecMatchPair'));

// ── Test 2: php -l on every touched/new file ──────────────────────────────────
$root = dirname(__DIR__);
$touched = [
    'core/bank_recon_matching.php',
    'api/account/import_bank_statement.php',
    'api/account/suggest_reconciliation_matches.php',
    'api/account/toggle_reconciliation_match.php',
    'api/account/get_reconciliation_lines.php',
    'api/account/create_entry_from_statement_line.php',
    'app/constant/accounts/bank_reconciliation.php',
    'app/constant/accounts/reconciliation_details.php',
    'migrations/2026_07_30_bank_transactions_matched_pair.php',
];
foreach ($touched as $rel) {
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $code);
    ok("php -l: $rel", $code === 0, implode(' ', $out));
}

// ── Fixture accounts ───────────────────────────────────────────────────────────
$bankId = (int)$pdo->query(
    "SELECT a.account_id FROM accounts a
      JOIN account_types at ON a.account_type_id = at.type_id
     WHERE at.category IN ('asset','cash') AND a.status='active'
     ORDER BY a.account_id LIMIT 1"
)->fetchColumn();
$expId = (int)$pdo->query(
    "SELECT account_id FROM accounts WHERE account_name LIKE '%expense%' AND status='active' LIMIT 1"
)->fetchColumn();
if (!$expId) {
    // Fall back to the account_types category — this DB's chart uses plain
    // names ("Salaries and Wages", "Marketing") rather than the word "expense".
    $expId = (int)$pdo->query(
        "SELECT a.account_id FROM accounts a JOIN account_types t ON a.account_type_id = t.type_id
          WHERE t.category = 'expense' AND a.status = 'active' ORDER BY a.account_id LIMIT 1"
    )->fetchColumn();
}
$incId = (int)$pdo->query(
    "SELECT account_id FROM accounts WHERE (account_name LIKE '%income%' OR account_name LIKE '%revenue%') AND status='active' LIMIT 1"
)->fetchColumn();
if (!$incId) {
    $incId = (int)$pdo->query(
        "SELECT a.account_id FROM accounts a JOIN account_types t ON a.account_type_id = t.type_id
          WHERE t.category = 'revenue' AND a.status = 'active' ORDER BY a.account_id LIMIT 1"
    )->fetchColumn();
}

if (!$bankId || !$expId) { echo "  SKIP: Fixture accounts not found.\n"; exit(0); }

// ── Pre-cleanup: remove any leftover T5-* fixtures from aborted prior runs ────
$pdo->exec("DELETE FROM bank_transactions WHERE reference_number LIKE 'T5-%' OR description LIKE 'Phase5 test%'");
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_number LIKE 'T5-%'");

$testDate = '2026-01-20';

/** Build a CSV file on disk and return its path. */
function writeCsvFixture(array $rows, array $headers = ['transaction_date','value_date','description','reference','transaction_type','amount','balance_after','category','counterparty_name','counterparty_account']): string {
    $path = tempnam(sys_get_temp_dir(), 'bankstmt_');
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
    return $path;
}

/** Simulate a $_FILES['statement_file'] entry pointing at a real temp file (no move_uploaded_file involved). */
function fakeUpload(string $path): array {
    return ['name' => 'statement.csv', 'type' => 'text/csv', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

/** Run an api/account/*.php file with the current $_POST/$_FILES/$_GET and return decoded JSON. */
function callApi(string $rel): array {
    global $root;
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';
    ob_start();
    include "$root/$rel";
    $raw = ob_get_clean();
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON response: ' . $raw];
}

$_SERVER['REQUEST_METHOD'] = 'POST';

// ── Test 3: rejects non-CSV extension ─────────────────────────────────────────
$badPath = tempnam(sys_get_temp_dir(), 'notcsv_');
rename($badPath, $badPath . '.txt'); $badPath .= '.txt';
file_put_contents($badPath, "not,a,csv\n");
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'add_new', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($badPath)];
$_FILES['statement_file']['name'] = 'statement.txt';
$res3 = callApi('api/account/import_bank_statement.php');
ok('import rejects non-CSV extension', $res3['success'] === false, json_encode($res3));
unlink($badPath);

// ── Test 4: rejects missing required columns ──────────────────────────────────
$badCsv = writeCsvFixture([['x' => 1]], ['not_a_real_column']);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'add_new', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($badCsv)];
$res4 = callApi('api/account/import_bank_statement.php');
ok('import rejects missing required columns', $res4['success'] === false, json_encode($res4));
unlink($badCsv);

// ── Test 5: creates statement-side rows ───────────────────────────────────────
$csv1 = writeCsvFixture([
    [$testDate, $testDate, 'Phase5 test statement deposit', 'T5-REF-001', 'deposit', '321.00', '', '', '', ''],
]);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'add_new', 'auto_match' => 'on', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($csv1)];
$res5 = callApi('api/account/import_bank_statement.php');
ok('import succeeds', $res5['success'] === true, json_encode($res5));
ok('import: 1 row imported', ($res5['results']['imported'] ?? 0) === 1, json_encode($res5));

$stmtRow = $pdo->query("SELECT * FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetch(PDO::FETCH_ASSOC);
ok('imported row exists', !empty($stmtRow));
ok('imported row has imported_from set', !empty($stmtRow['imported_from'] ?? null), json_encode($stmtRow));
ok('imported row matching_status is unmatched', ($stmtRow['matching_status'] ?? '') === 'unmatched');
$stmtTxnId = (int)($stmtRow['transaction_id'] ?? 0);

// ── Test 6: re-import with add_new is idempotent ──────────────────────────────
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'add_new', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($csv1)];
$res6 = callApi('api/account/import_bank_statement.php');
$cnt6 = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetchColumn();
ok('re-import (add_new) does not duplicate', $cnt6 === 1, "count=$cnt6, res=" . json_encode($res6));

// ── Test 7: update action updates existing row, no duplicate ─────────────────
$csv1b = writeCsvFixture([
    [$testDate, $testDate, 'Phase5 test statement deposit UPDATED', 'T5-REF-001', 'deposit', '321.00', '', '', '', ''],
]);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'update', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($csv1b)];
$res7 = callApi('api/account/import_bank_statement.php');
$cnt7 = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetchColumn();
$desc7 = $pdo->query("SELECT description FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetchColumn();
ok('update action: still exactly 1 row', $cnt7 === 1, "count=$cnt7");
ok('update action: description updated', $desc7 === 'Phase5 test statement deposit UPDATED', "desc=$desc7");
unlink($csv1b);

// ── Test 8: replace action — no data loss, no duplication ────────────────────
$csv1c = writeCsvFixture([
    [$testDate, $testDate, 'Phase5 test statement deposit REPLACED', 'T5-REF-001', 'deposit', '321.00', '', '', '', ''],
]);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'replace', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($csv1c)];
$res8 = callApi('api/account/import_bank_statement.php');
$rows8 = $pdo->query("SELECT description FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetchAll(PDO::FETCH_COLUMN);
ok('replace action: exactly 1 row survives', count($rows8) === 1, 'rows=' . json_encode($rows8));
ok('replace action: row is the NEW version', ($rows8[0] ?? '') === 'Phase5 test statement deposit REPLACED', 'rows=' . json_encode($rows8));
unlink($csv1c);
unlink($csv1);

// Refresh $stmtTxnId (replace deletes+reinserts, so the id may have changed)
$stmtRow = $pdo->query("SELECT * FROM bank_transactions WHERE reference_number = 'T5-REF-001'")->fetch(PDO::FETCH_ASSOC);
$stmtTxnId = (int)($stmtRow['transaction_id'] ?? 0);

// ── Test 9: replace never touches a CONFIRMED matched row ────────────────────
// Manually confirm-match the current row against a throwaway reconciliation,
// then attempt another replace import covering the same date — it must survive.
$pdo->exec("INSERT INTO bank_reconciliations
    (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end,
     statement_balance, book_balance, adjusted_balance, opening_balance, difference, status, prepared_by, created_at)
    VALUES ('T5-GUARD-REC', $bankId, '$testDate', '2026-01-01', '2026-01-31', 0, 0, 0, 0, 0, 'pending', 1, NOW())");
$guardRecId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE bank_transactions SET matching_status='matched', reconciliation_id=? WHERE transaction_id=?")
    ->execute([$guardRecId, $stmtTxnId]);

$csv1d = writeCsvFixture([
    [$testDate, $testDate, 'Phase5 test statement deposit SHOULD-NOT-OVERWRITE', 'T5-REF-001', 'deposit', '321.00', '', '', '', ''],
]);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'replace', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($csv1d)];
callApi('api/account/import_bank_statement.php');
$rows9 = $pdo->query("SELECT transaction_id, description, matching_status FROM bank_transactions WHERE reference_number = 'T5-REF-001' ORDER BY transaction_id")->fetchAll(PDO::FETCH_ASSOC);
$stillMatched = null;
foreach ($rows9 as $r) if ((int)$r['transaction_id'] === $stmtTxnId) $stillMatched = $r;
ok('replace never deletes/overwrites an already-matched row', $stillMatched !== null && $stillMatched['matching_status'] === 'matched', json_encode($rows9));
unlink($csv1d);

// Revert the guard match so later tests treat this row as unmatched again.
$pdo->prepare("UPDATE bank_transactions SET matching_status='unmatched', reconciliation_id=NULL WHERE transaction_id=?")->execute([$stmtTxnId]);
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_id = $guardRecId");

// ── Test 10-11: suggestBankRecMatches pairs a book-side + statement-side row ──
// Book-side row: same amount/type/date, written the way core/bank_register.php would.
$bookTxnId = recordBankTransaction($pdo, $bankId, 321.00, 'deposit', $testDate, 'T5-BOOK-REF', 'Phase5 test book-side deposit', 1);
ok('book-side fixture row created', $bookTxnId !== null);

$suggested = suggestBankRecMatches($pdo, $bankId);
$foundPair = null;
foreach ($suggested as $s) {
    if ($s['book_transaction_id'] === $bookTxnId && $s['statement_transaction_id'] === $stmtTxnId) { $foundPair = $s; break; }
}
ok('suggestBankRecMatches pairs the book row with the statement row', $foundPair !== null, json_encode($suggested));

$bookAfter = $pdo->query("SELECT matched_transaction_id, matching_status FROM bank_transactions WHERE transaction_id = $bookTxnId")->fetch(PDO::FETCH_ASSOC);
$stmtAfter = $pdo->query("SELECT matched_transaction_id, matching_status FROM bank_transactions WHERE transaction_id = $stmtTxnId")->fetch(PDO::FETCH_ASSOC);
ok('book row points at statement row', (int)($bookAfter['matched_transaction_id'] ?? 0) === $stmtTxnId, json_encode($bookAfter));
ok('statement row points at book row', (int)($stmtAfter['matched_transaction_id'] ?? 0) === $bookTxnId, json_encode($stmtAfter));
ok('suggestion does NOT flip matching_status (book side)', ($bookAfter['matching_status'] ?? '') === 'unmatched');
ok('suggestion does NOT flip matching_status (statement side)', ($stmtAfter['matching_status'] ?? '') === 'unmatched');

// ── Test 12-14: confirm the pair via the worksheet's match action ────────────
$bookBalanceAsOf = accountLedgerBalanceAsOf($pdo, $bankId, $testDate);
$pdo->exec("INSERT INTO bank_reconciliations
    (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end,
     statement_balance, book_balance, adjusted_balance, opening_balance, difference, status, prepared_by, created_at)
    VALUES ('T5-MAIN-REC', $bankId, '$testDate', '2026-01-01', '2026-01-31',
            " . (float)$bookBalanceAsOf . ", " . (float)$bookBalanceAsOf . ", 0, 0, 0, 'pending', 1, NOW())");
$mainRecId = (int)$pdo->lastInsertId();

// Tick the STATEMENT-side checkbox — the pair-aware code path must confirm BOTH sides.
$_POST = ['reconciliation_id' => $mainRecId, 'action' => 'match', 'transaction_id' => $stmtTxnId, '_csrf' => csrf_token()];
$res12 = callApi('api/account/toggle_reconciliation_match.php');
ok('toggle match (pair-aware) succeeds', $res12['success'] === true, json_encode($res12));

$bookFinal = $pdo->query("SELECT matching_status, reconciliation_id FROM bank_transactions WHERE transaction_id = $bookTxnId")->fetch(PDO::FETCH_ASSOC);
$stmtFinal = $pdo->query("SELECT matching_status, reconciliation_id FROM bank_transactions WHERE transaction_id = $stmtTxnId")->fetch(PDO::FETCH_ASSOC);
ok('ticking the statement side also matches the book side', ($bookFinal['matching_status'] ?? '') === 'matched', json_encode($bookFinal));
ok('both sides share the same reconciliation_id', (int)($bookFinal['reconciliation_id'] ?? 0) === $mainRecId && (int)($stmtFinal['reconciliation_id'] ?? 0) === $mainRecId);

// ── Test 14: reconciled_book math — no double count ───────────────────────────
// Both the book-side row (already inside book_balance, being a GL mirror) and
// the statement-side row are now "matched" -> excluded from uncleared_movement
// -> reconciled_book must equal the plain book_balance, difference must be 0
// (the fixture's statement_balance was set equal to book_balance above).
$_GET = ['reconciliation_id' => $mainRecId];
$linesRes = callApi('api/account/get_reconciliation_lines.php');
ok('get_reconciliation_lines succeeds', $linesRes['success'] === true, json_encode($linesRes));
$reconciledBook = $linesRes['summary']['reconciled_book'] ?? null;
ok('reconciled_book == book_balance (paired lines fully excluded from uncleared)',
    $reconciledBook !== null && abs((float)$reconciledBook - (float)$bookBalanceAsOf) < 0.01,
    "reconciled_book=$reconciledBook book_balance=$bookBalanceAsOf");
ok('difference is zero with the pair fully matched', abs((float)($linesRes['summary']['difference'] ?? 999)) < 0.01, json_encode($linesRes['summary'] ?? []));

// ── Test 15: unmatch reverts both sides, keeps the suggestion pointer ────────
$_POST = ['reconciliation_id' => $mainRecId, 'action' => 'unmatch', 'transaction_id' => $stmtTxnId, '_csrf' => csrf_token()];
$res15 = callApi('api/account/toggle_reconciliation_match.php');
ok('unmatch succeeds', $res15['success'] === true, json_encode($res15));
$bookU = $pdo->query("SELECT matching_status, reconciliation_id, matched_transaction_id FROM bank_transactions WHERE transaction_id = $bookTxnId")->fetch(PDO::FETCH_ASSOC);
$stmtU = $pdo->query("SELECT matching_status, reconciliation_id, matched_transaction_id FROM bank_transactions WHERE transaction_id = $stmtTxnId")->fetch(PDO::FETCH_ASSOC);
ok('unmatch reverts book side to unmatched', ($bookU['matching_status'] ?? '') === 'unmatched', json_encode($bookU));
ok('unmatch reverts statement side to unmatched', ($stmtU['matching_status'] ?? '') === 'unmatched', json_encode($stmtU));
ok('unmatch keeps the suggestion pointer (book side)', (int)($bookU['matched_transaction_id'] ?? 0) === $stmtTxnId);
ok('unmatch keeps the suggestion pointer (statement side)', (int)($stmtU['matched_transaction_id'] ?? 0) === $bookTxnId);

// ── Test 16: ignore breaks the pairing ────────────────────────────────────────
$_POST = ['reconciliation_id' => $mainRecId, 'action' => 'ignore', 'transaction_id' => $stmtTxnId, '_csrf' => csrf_token()];
$res16 = callApi('api/account/toggle_reconciliation_match.php');
ok('ignore succeeds', $res16['success'] === true, json_encode($res16));
$bookI = $pdo->query("SELECT matched_transaction_id FROM bank_transactions WHERE transaction_id = $bookTxnId")->fetch(PDO::FETCH_ASSOC);
$stmtI = $pdo->query("SELECT matching_status, matched_transaction_id FROM bank_transactions WHERE transaction_id = $stmtTxnId")->fetch(PDO::FETCH_ASSOC);
ok('ignore marks the line ignored', ($stmtI['matching_status'] ?? '') === 'ignored', json_encode($stmtI));
ok('ignore clears the pairing on the ignored side', empty($stmtI['matched_transaction_id']), json_encode($stmtI));
ok('ignore clears the pairing on the counterpart too', empty($bookI['matched_transaction_id']), json_encode($bookI));
// Un-ignore for cleanliness of later assertions.
$pdo->exec("UPDATE bank_transactions SET matching_status='unmatched' WHERE transaction_id IN ($bookTxnId, $stmtTxnId)");

// ── Test 17: create_entry_from_statement_line REJECTS a book-side line ───────
$_POST = ['reconciliation_id' => $mainRecId, 'transaction_id' => $bookTxnId, 'gl_account_id' => $incId ?: $expId, 'memo' => 'should be rejected', '_csrf' => csrf_token()];
$res17 = callApi('api/account/create_entry_from_statement_line.php');
ok('create_entry_from_statement_line rejects a book-side line', $res17['success'] === false, json_encode($res17));

// ── Test 18-19: succeeds for a genuine unmatched statement-side line ─────────
$soloCsv = writeCsvFixture([
    [$testDate, $testDate, 'Phase5 test bank-only fee', 'T5-BANKFEE-001', 'withdrawal', '15.00', '', '', '', ''],
]);
$_POST  = ['bank_account_id' => $bankId, 'import_action' => 'add_new', '_csrf' => csrf_token()];
$_FILES = ['statement_file' => fakeUpload($soloCsv)];
callApi('api/account/import_bank_statement.php');
unlink($soloCsv);
$soloTxnId = (int)$pdo->query("SELECT transaction_id FROM bank_transactions WHERE reference_number = 'T5-BANKFEE-001'")->fetchColumn();
ok('bank-only fixture row imported', $soloTxnId > 0);

$_POST = ['reconciliation_id' => $mainRecId, 'transaction_id' => $soloTxnId, 'gl_account_id' => $expId, 'memo' => 'Bank fee', '_csrf' => csrf_token()];
$res18 = callApi('api/account/create_entry_from_statement_line.php');
ok('create_entry_from_statement_line succeeds for a real statement-only line', $res18['success'] === true, json_encode($res18));
$entryId18 = (int)($res18['entry_id'] ?? 0);
ok('a journal entry id was returned', $entryId18 > 0);

$soloAfter = $pdo->query("SELECT matching_status, reconciliation_id FROM bank_transactions WHERE transaction_id = $soloTxnId")->fetch(PDO::FETCH_ASSOC);
ok('the line is auto-matched after posting', ($soloAfter['matching_status'] ?? '') === 'manual', json_encode($soloAfter));
ok('the line is tied to this reconciliation', (int)($soloAfter['reconciliation_id'] ?? 0) === $mainRecId);

// ── Cleanup ────────────────────────────────────────────────────────────────────
if ($entryId18 > 0) {
    $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id = $entryId18");
    $pdo->exec("DELETE FROM journal_entries WHERE entry_id = $entryId18");
}
if (!empty($res8['results']['imported']) || true) {
    $pdo->exec("DELETE FROM bank_reconciliation_adjustments WHERE reconciliation_id IN ($mainRecId)");
}
$pdo->exec("DELETE FROM bank_transactions WHERE reference_number IN ('T5-REF-001','T5-BOOK-REF','T5-BANKFEE-001') OR description LIKE 'Phase5 test%'");
$pdo->exec("DELETE FROM bank_reconciliations WHERE reconciliation_number LIKE 'T5-%'");

echo "\n--- Phase 5 results: $pass passed, $fail failed ---\n\n";
exit($fail > 0 ? 1 : 0);
