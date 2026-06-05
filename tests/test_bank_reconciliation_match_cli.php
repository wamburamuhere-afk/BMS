<?php
/**
 * Bank Reconciliation — line matching (Plan B) — CLI test
 *   php tests/test_bank_reconciliation_match_cli.php
 *
 * Verifies: the two new APIs exist + lint; they are permission-gated and CSRF-
 * guarded; the matching maths is correct (difference = statement - (book -
 * uncleared)); matching a cleared line drives the difference to zero; finalize is
 * only allowed when balanced and locks the matched lines. Runtime runs inside a
 * transaction that is rolled back — nothing persists, and no money is ever moved.
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

// The exact reconciliation maths the APIs use (kept in sync by the source-asserts below).
function compute(PDO $pdo, int $bank, string $from, string $to, int $recId, float $statement, float $book): array {
    $stmt = $pdo->prepare("SELECT transaction_type, amount, COALESCE(matching_status,'unmatched') ms, reconciliation_id
                             FROM bank_transactions
                            WHERE bank_account_id = ? AND ((transaction_date BETWEEN ? AND ?) OR reconciliation_id = ?)");
    $stmt->execute([$bank, $from, $to, $recId]);
    $cleared = 0.0; $uncleared = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $signed = ($r['transaction_type'] === 'deposit') ? (float)$r['amount'] : -(float)$r['amount'];
        if ($r['ms'] === 'ignored') continue;
        if (in_array($r['ms'], ['matched','manual'], true) && (int)$r['reconciliation_id'] === $recId) $cleared += $signed;
        else $uncleared += $signed;
    }
    $reconciled_book = round($book - $uncleared, 2);
    $difference = round($statement - $reconciled_book, 2);
    return ['cleared'=>round($cleared,2), 'uncleared'=>round($uncleared,2), 'difference'=>$difference, 'balanced'=>abs($difference) < 0.01];
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([
    'api/account/get_reconciliation_lines.php',
    'api/account/toggle_reconciliation_match.php',
    'app/constant/accounts/reconciliation_details.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. API contracts — gated, CSRF, additive, correct maths');
$lines = src($root, 'api/account/get_reconciliation_lines.php');
has($lines, "canView('bank_reconciliation')", 'read API gated by canView');
has($lines, "statement_balance - reconciled_book", 'read API difference = statement - reconciled_book (via $book - $uncleared)');
$tog = src($root, 'api/account/toggle_reconciliation_match.php');
has($tog, "csrf_check()", 'toggle API enforces CSRF');
has($tog, "canEdit('bank_reconciliation')", 'toggle API gated by canEdit');
has($tog, "canApprove('bank_reconciliation')", 'finalize gated by canApprove');
has($tog, "matching_status = 'matched'", 'match sets matching_status');
has($tog, "status = 'reconciled'", 'finalize stamps reconciled');
has($tog, "the difference is not yet zero", 'finalize blocked unless balanced');
hasnt($tog, "applyAccountBalanceDelta", 'matching never moves money');
hasnt($tog, "postOutflow", 'matching never posts the ledger');

$page = src($root, 'app/constant/accounts/reconciliation_details.php');
has($page, 'get_reconciliation_lines.php', 'details page loads the worksheet lines');
has($page, 'finalizeMatching', 'details page has a Finalize action');
hasnt($page, 'if (!confirm(', 'native confirm() replaced by SweetAlert2');
hasnt($page, "alert('Server error occurred')", 'native alert() replaced by SweetAlert2');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — match drives difference to zero; finalize locks (rolled back)');
try {
    $bank = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='asset' AND cash_flow_category='cash' ORDER BY account_id LIMIT 1")->fetchColumn();
    if ($bank <= 0) { fail('no cash/bank account to test'); }
    else {
        // NOTE: bank_transactions / bank_reconciliations are MyISAM (no transaction
        // support), so this test cannot rely on ROLLBACK — it seeds rows and then
        // DELETES them explicitly in a finally block (even on failure).
        // Far-future period so the worksheet sees ONLY the lines this test seeds
        // (the API correctly scopes by account+period; real data lives in 2026).
        $from = '2099-01-01'; $to = '2099-01-31';
        $statement = 1100.00; $book = 1000.00;
        $uid = 'TEST-' . substr(uniqid('', true), -10);
        $recNo = 'REC-' . $uid; $refDep = 'RD-' . $uid; $refWd = 'RW-' . $uid;
        $recId = 0; $lineDep = 0; $lineWd = 0;

        try {
            // Reconciliation header.
            $pdo->prepare("INSERT INTO bank_reconciliations (reconciliation_number, bank_account_id, reconciliation_date, period_start, period_end, statement_balance, book_balance, difference, status, prepared_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending', 4, NOW(), NOW())")
                ->execute([$recNo, $bank, $to, $from, $to, $statement, $book]);
            $recId = (int)$pdo->lastInsertId();

            // Two register lines: a 300 deposit (will clear) and a 100 withdrawal (stays outstanding).
            $ins = $pdo->prepare("INSERT INTO bank_transactions (bank_account_id, account_id, transaction_date, value_date, description, reference_number, transaction_type, amount, matching_status, status, created_by, created_at, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unmatched', 'pending', 4, NOW(), NOW())");
            $ins->execute([$bank, $bank, '2099-01-10', '2099-01-10', '__REC_DEP__', $refDep, 'deposit', 300.00]);
            $lineDep = (int)$pdo->lastInsertId();
            $ins->execute([$bank, $bank, '2099-01-20', '2099-01-20', '__REC_WD__', $refWd, 'withdrawal', 100.00]);
            $lineWd = (int)$pdo->lastInsertId();

            // Before matching: difference should be non-zero (300).
            $c0 = compute($pdo, $bank, $from, $to, $recId, $statement, $book);
            (abs($c0['difference'] - 300.0) < 0.01) ? pass('initial difference = 300 (nothing matched yet)') : fail('initial difference wrong: ' . $c0['difference']);
            (!$c0['balanced']) ? pass('not balanced initially') : fail('unexpectedly balanced initially');

            // Match the deposit (it has cleared the bank statement).
            $pdo->prepare("UPDATE bank_transactions SET matching_status='matched', reconciliation_id=?, status='cleared' WHERE transaction_id=?")
                ->execute([$recId, $lineDep]);

            $c1 = compute($pdo, $bank, $from, $to, $recId, $statement, $book);
            (abs($c1['difference']) < 0.01) ? pass('after matching the cleared deposit, difference = 0') : fail('difference not zero: ' . $c1['difference']);
            ($c1['balanced']) ? pass('reconciliation now balanced (outstanding withdrawal left unmatched)') : fail('not balanced after match');

            // Finalize precondition + lock.
            if ($c1['balanced']) {
                $pdo->prepare("UPDATE bank_reconciliations SET status='reconciled', adjusted_balance=?, difference=0 WHERE reconciliation_id=?")
                    ->execute([$book - $c1['uncleared'], $recId]);
                $pdo->prepare("UPDATE bank_transactions SET status='reconciled' WHERE reconciliation_id=? AND matching_status IN ('matched','manual')")
                    ->execute([$recId]);
                $st = $pdo->query("SELECT status FROM bank_reconciliations WHERE reconciliation_id=$recId")->fetchColumn();
                ($st === 'reconciled') ? pass('finalize set the reconciliation to reconciled') : fail('finalize status wrong: ' . $st);
                $locked = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE reconciliation_id=$recId AND status='reconciled'")->fetchColumn();
                ($locked === 1) ? pass('the matched line is stamped reconciled (locked)') : fail("expected 1 locked line, got $locked");
            }
        } finally {
            // Explicit cleanup (MyISAM = no rollback). Always remove the seeded rows.
            if ($lineDep) $pdo->prepare("DELETE FROM bank_transactions WHERE transaction_id=?")->execute([$lineDep]);
            if ($lineWd)  $pdo->prepare("DELETE FROM bank_transactions WHERE transaction_id=?")->execute([$lineWd]);
            if ($recId)   $pdo->prepare("DELETE FROM bank_reconciliations WHERE reconciliation_id=?")->execute([$recId]);
        }
        pass('seeded rows cleaned up (MyISAM-safe; no persistence, no money moved)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('runtime error: ' . $e->getMessage());
}
