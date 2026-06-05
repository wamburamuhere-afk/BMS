<?php
/**
 * Recurring Transactions (Plan C) — CLI test
 *   php tests/test_recurring_cli.php
 *
 * Verifies: files exist + lint; migration applied; route/menu + cron wired; the
 * engine generates a PENDING expense (no money moves), advances the schedule, is
 * idempotent (no double-generate per due date), respects occurrences/end, and a
 * paused profile generates nothing. Runtime runs inside a rolled-back transaction.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/recurring.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');
foreach ([
    'migrations/2026_06_10_recurring.php', 'core/recurring.php',
    'cron/run_recurring.php', 'api/account/run_recurring_now.php',
    'api/account/save_recurring_profile.php', 'api/account/update_recurring_status.php',
    'app/constant/accounts/recurring.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration + wiring');
($pdo->query("SHOW TABLES LIKE 'recurring_profiles'")->fetch()) ? pass('recurring_profiles table exists') : fail('recurring_profiles missing');
($pdo->query("SHOW TABLES LIKE 'recurring_runs'")->fetch()) ? pass('recurring_runs table exists') : fail('recurring_runs missing');
has(src($root, 'roots.php'), "'recurring' => ACCOUNTS_DIR . '/recurring.php'", 'recurring route registered');
has(src($root, 'header.php'), "getUrl('recurring')", 'Recurring menu link present');
has(src($root, 'header.php'), "cron/run_recurring.php", 'cron wired into header (throttled)');
has(src($root, 'cron/run_recurring.php'), "recurring_last_run", 'cron throttled once-per-day');
$eng = src($root, 'core/recurring.php');
has($eng, "'pending'", 'generated expense is pending (post-gated, no money moves)');
has($eng, "already_generated", 'engine is idempotent via the unique run row');
has(src($root, 'migrations/2026_06_10_recurring.php'), "UNIQUE KEY uniq_profile_date", 'recurring_runs has a per-profile+date unique key');

// ─────────────────────────────────────────────────────────────────────────
section('3. Runtime — generate, advance, idempotent, occurrences, pause (rolled back)');
try {
    $expAcc = (int)$pdo->query("SELECT account_id FROM accounts WHERE status='active' AND account_type='expense' LIMIT 1")->fetchColumn();
    if ($expAcc <= 0) { fail('need an expense account'); }
    else {
        $pdo->beginTransaction();
        $tpl = json_encode(['amount' => 500.00, 'expense_account_id' => $expAcc, 'description' => 'Office rent']);

        // A due monthly profile with 2 occurrences left.
        $pdo->prepare("INSERT INTO recurring_profiles (name, doc_type, template_json, frequency, interval_count, start_date, next_run_date, occurrences_left, status, created_by)
                       VALUES ('Rent','expense',?,'monthly',1,?,?,2,'active',4)")
            ->execute([$tpl, date('Y-m-d'), date('Y-m-d')]);
        $pid = (int)$pdo->lastInsertId();
        $dueDate = date('Y-m-d');

        $prof = $pdo->query("SELECT * FROM recurring_profiles WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
        $r1 = recurringGenerate($pdo, $prof);
        (!empty($r1['generated']) && $r1['doc_id']) ? pass('engine generated one expense') : fail('did not generate: ' . json_encode($r1));

        $st = $pdo->query("SELECT status, amount FROM expenses WHERE expense_id=" . (int)$r1['doc_id'])->fetch(PDO::FETCH_ASSOC);
        ($st && $st['status'] === 'pending') ? pass('generated expense is pending (no money moved)') : fail('expense status: ' . ($st['status'] ?? '?'));
        (abs((float)$st['amount'] - 500.0) < 0.01) ? pass('generated expense amount matches template (500)') : fail('amount wrong: ' . $st['amount']);
        ((int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE expense_id=" . (int)$r1['doc_id'] . " AND transaction_id IS NULL")->fetchColumn() === 1)
            ? pass('generated expense has NO ledger transaction (post-gated)') : fail('unexpected transaction_id on generated expense');

        // Schedule advanced + occurrences decremented.
        $next = $pdo->query("SELECT next_run_date FROM recurring_profiles WHERE id=$pid")->fetchColumn();
        ($next > $dueDate) ? pass("next_run_date advanced ($dueDate -> $next)") : fail("next_run_date not advanced: $next");
        ((int)$pdo->query("SELECT occurrences_left FROM recurring_profiles WHERE id=$pid")->fetchColumn() === 1)
            ? pass('occurrences_left decremented to 1') : fail('occurrences not decremented');

        // Idempotency: regenerating for the SAME (original) due date is a no-op.
        $prof['next_run_date'] = $dueDate;
        $r2 = recurringGenerate($pdo, $prof);
        ($r2['reason'] === 'already_generated') ? pass('idempotent: same due date does not double-generate') : fail('idempotency broken: ' . $r2['reason']);

        // Generate the final occurrence → profile ends.
        $prof2 = $pdo->query("SELECT * FROM recurring_profiles WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
        recurringGenerate($pdo, $prof2);
        ($pdo->query("SELECT status FROM recurring_profiles WHERE id=$pid")->fetchColumn() === 'ended')
            ? pass('profile ends after the last occurrence') : fail('profile did not end on occurrences exhausted');

        // A paused profile is not returned as due.
        $pdo->prepare("INSERT INTO recurring_profiles (name, doc_type, template_json, frequency, interval_count, start_date, next_run_date, status, created_by)
                       VALUES ('Paused','expense',?,'monthly',1,?,?,'paused',4)")
            ->execute([$tpl, date('Y-m-d'), date('Y-m-d')]);
        $pausedId = (int)$pdo->lastInsertId();
        $due = recurringDue($pdo);
        $hasPaused = false; foreach ($due as $d) { if ((int)$d['id'] === $pausedId) $hasPaused = true; }
        (!$hasPaused) ? pass('paused profile is not picked up as due') : fail('paused profile was returned as due');

        $pdo->rollBack();
        pass('all changes rolled back (no persistence)');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('runtime error: ' . $e->getMessage());
}
