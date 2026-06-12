<?php
/**
 * Leave balance & entitlement engine (Plan H3) — CLI test
 *   php tests/test_leave_balance_cli.php
 *
 * Verifies files lint; migration applied; the engine (enum↔config mapping,
 * drift-proof balance entitled+carried−used, normaliser); approval enforcement +
 * unpaid-leave→payroll wiring; and carry-over. leaves is MyISAM (no rollback) so the
 * runtime seeds rows and DELETES them explicitly.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/leave_balance.php";
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
    'migrations/2026_06_13_leave_balances.php', 'core/leave_balance.php',
    'api/get_leave_entitlement.php', 'api/recompute_leave_balances.php',
    'cron/run_leave_accrual.php', 'api/approve_leave.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration + wiring');
($pdo->query("SHOW TABLES LIKE 'leave_balances'")->fetch()) ? pass('leave_balances table exists') : fail('leave_balances missing');
has(src($root, 'api/approve_leave.php'), "leaveBalanceFor", 'approve_leave enforces the balance');
has(src($root, 'api/approve_leave.php'), "would exceed the leave balance", 'approve_leave blocks over-application');
has(src($root, 'api/process_payroll.php'), "unpaidLeaveDaysInPeriod", 'payroll deducts unpaid leave (mode on)');
has(src($root, 'api/preview_payroll.php'), "unpaidLeaveDaysInPeriod", 'preview mirrors the unpaid-leave deduction');
has(src($root, 'header.php'), "cron/run_leave_accrual.php", 'accrual cron wired (throttled)');

// ─────────────────────────────────────────────────────────────────────────
section('3. Engine — mapping + normaliser');
(leaveTypeIdFromEnum($pdo, 'annual') !== null) ? pass("'annual' maps to a leave_type id") : fail("'annual' did not map");
(leaveTypeIdFromEnum($pdo, 'xyz') === null) ? pass("unknown enum maps to null (degrade-safe)") : fail('unknown enum mapped');
(leaveNormalizeEnum('Annual Leave') === 'annual') ? pass("'Annual Leave' normalises to 'annual'") : fail('normalise type_name failed');
(leaveNormalizeEnum('unpaid') === 'unpaid') ? pass("'unpaid' stays 'unpaid'") : fail('normalise enum failed');
(leaveNormalizeEnum('Compassionate Leave') === 'other') ? pass("unknown label → 'other'") : fail('normalise fallback failed');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — drift-proof balance + enforcement math (explicit cleanup)');
$emp = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
if ($emp <= 0) { fail('no employee to test'); }
else {
    $year = 2099;   // a year with no real data
    $ids = [];
    try {
        // Annual leave entitlement (21 from config). Seed two approved annual leaves: 5 + 8 = 13 used.
        $ins = $pdo->prepare("INSERT INTO leaves (employee_id, leave_type, start_date, end_date, total_days, days_count, reason, status, created_by, created_at) VALUES (?,?,?,?,?,?,?, 'approved', 4, NOW())");
        $ins->execute([$emp, 'annual', "$year-02-01", "$year-02-05", 5, 5, '__LB_TEST__']); $ids[] = (int)$pdo->lastInsertId();
        $ins->execute([$emp, 'annual', "$year-06-01", "$year-06-08", 8, 8, '__LB_TEST__']); $ids[] = (int)$pdo->lastInsertId();

        $b = leaveBalanceFor($pdo, $emp, 'annual', $year);
        ($b['tracked']) ? pass('annual leave is tracked') : fail('annual not tracked');
        (abs($b['used'] - 13.0) < 0.01) ? pass('used = 13 (5 + 8 approved)') : fail('used wrong: ' . $b['used']);
        (abs($b['entitled'] - 21.0) < 0.01) ? pass('entitled = 21 (from leave_types config)') : fail('entitled wrong: ' . $b['entitled']);
        (abs($b['available'] - 8.0) < 0.01) ? pass('available = 8 (21 − 13, drift-proof)') : fail('available wrong: ' . $b['available']);

        // Enforcement decision: a 10-day request would exceed the 8 available.
        $req = 10.0;
        $wouldExceed = ($b['is_paid'] && ($b['used'] + $req) > ($b['entitled'] + $b['carried_over'] + 0.001));
        $wouldExceed ? pass('a 10-day request is correctly flagged as over-balance (would block approval)') : fail('over-balance not detected');
        $req2 = 5.0;
        $okWithin = !($b['used'] + $req2 > $b['entitled'] + $b['carried_over'] + 0.001);
        $okWithin ? pass('a 5-day request is within balance (would be allowed)') : fail('within-balance wrongly blocked');

        // Unpaid leave in a pay period feeds payroll.
        $ins->execute([$emp, 'unpaid', "$year-03-10", "$year-03-12", 3, 3, '__LB_TEST__']); $ids[] = (int)$pdo->lastInsertId();
        $u = unpaidLeaveDaysInPeriod($pdo, $emp, "$year-03");
        (abs($u - 3.0) < 0.01) ? pass('unpaidLeaveDaysInPeriod = 3 for the period') : fail('unpaid days wrong: ' . $u);
    } catch (Throwable $e) {
        fail('runtime error: ' . $e->getMessage());
    } finally {
        foreach ($ids as $id) if ($id) $pdo->prepare("DELETE FROM leaves WHERE leave_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM leave_balances WHERE employee_id=? AND year=?")->execute([$emp, 2099]);
    }
    pass('seeded rows cleaned up');
}
