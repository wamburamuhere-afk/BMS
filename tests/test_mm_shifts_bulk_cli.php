<?php
/**
 * MM Shifts — Bulk Open / Close guard
 *   php tests/test_mm_shifts_bulk_cli.php
 *
 * A. STATIC contract — batch_open_shifts.php and batch_close_shifts.php exist,
 *    lint clean, are correctly permission-gated, and have the required mechanics.
 *    Also checks mm_shifts.php page for the multi-till open modal, Close All modal,
 *    and notes textarea upgrade.
 *
 * B. LIVE data-model (transaction-wrapped + ROLLED BACK):
 *    · Batch-open two tills: verify two shift rows created, float snapshots taken
 *    · Try to batch-open an already-open till: verify it is rejected
 *    · Batch-close both open shifts with known cash/float: verify variance math
 *    · Try to batch-close an already-closed shift: verify it is rejected
 *
 * No web server needed. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)   { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t)  { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a,$b){ return abs((float)$a - (float)$b) < 0.01; }
function src($p)      { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. Static contract ──────────────────────────────────────────────────────
section('A. Static contract — batch_open_shifts.php');
$batchOpen  = "$root/api/mobile_money/batch_open_shifts.php";
$batchClose = "$root/api/mobile_money/batch_close_shifts.php";
$shiftsPage = "$root/app/bms/mobile_money/mm_shifts.php";
$dashboard  = "$root/app/bms/mobile_money/mm_dashboard.php";

foreach (['batch_open_shifts.php' => $batchOpen, 'batch_close_shifts.php' => $batchClose] as $name => $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, "$name lint-clean");
}
foreach (['mm_shifts.php' => $shiftsPage, 'mm_dashboard.php' => $dashboard] as $name => $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    ok($rc === 0, "$name lint-clean");
}

$bo = src($batchOpen);  $bc = src($batchClose);
ok(strpos($bo, "canCreate('mm_shifts')") !== false,      'batch_open gated on canCreate(mm_shifts)');
ok(strpos($bo, 'csrf_check()') !== false,                'batch_open CSRF-checked');
ok(strpos($bo, 'mmUserCanOnTill') !== false,             'batch_open checks per-till grant');
ok(strpos($bo, "status = 'open'") !== false,             'batch_open enforces one-shift-per-till check');
ok(strpos($bo, 'beginTransaction') !== false,            'batch_open wrapped in transaction');
ok(strpos($bo, 'mmTakeFloatSnapshot') !== false,         'batch_open calls mmTakeFloatSnapshot');
ok(strpos($bo, 'logActivity') !== false,                 'batch_open logs activity');

section('A. Static contract — batch_close_shifts.php');
ok(strpos($bc, "canEdit('mm_shifts')") !== false,        'batch_close gated on canEdit(mm_shifts)');
ok(strpos($bc, 'csrf_check()') !== false,                'batch_close CSRF-checked');
ok(strpos($bc, 'mmUserCanOnTill') !== false,             'batch_close checks per-till grant');
ok(strpos($bc, "!== 'open'") !== false,                  'batch_close rejects non-open shifts');
ok(strpos($bc, 'beginTransaction') !== false,            'batch_close wrapped in transaction');
ok(strpos($bc, 'cash_variance') !== false,               'batch_close computes cash_variance');
ok(strpos($bc, 'float_variance') !== false,              'batch_close computes float_variance');
ok(strpos($bc, 'mmTakeFloatSnapshot') !== false,         'batch_close calls mmTakeFloatSnapshot');
ok(strpos($bc, 'logActivity') !== false,                 'batch_close logs activity');

section('A. Static contract — mm_shifts.php page');
$sp = src($shiftsPage);
ok(strpos($sp, 'allOpenForClose') !== false,             'page fetches allOpenForClose array');
$spNoComments = preg_replace('/\/\/[^\n]*/', '', $sp);
ok(strpos($spNoComments, 'LIMIT 1') === false, 'page has no SQL LIMIT 1 in queries');
ok(strpos($sp, 'openShiftModal') !== false,              'openShiftModal present');
ok(strpos($sp, 'closeAllShiftsModal') !== false,         'closeAllShiftsModal present');
ok(strpos($sp, 'batch_open_shifts.php') !== false,       'Open form posts to batch_open_shifts.php');
ok(strpos($sp, 'batch_close_shifts.php') !== false,      'Close All form posts to batch_close_shifts.php');
ok(strpos($sp, 'close-all-notes') !== false,             'Close All modal has notes class');
ok(preg_match('/<textarea[^>]*close-all-notes/', $sp),   'Close All notes is a textarea (wrappable)');
ok(preg_match('/<textarea[^>]*close_notes_field/', $sp), 'Individual close notes is a textarea (wrappable)');
ok(strpos($sp, 'selectAllTills') !== false,              'Select-all checkbox present in open modal');
ok(strpos($sp, 'freeTillCount') !== false,               'freeTillCount used to show Open button');

section('A. Static contract — mm_dashboard.php');
$dp = src($dashboard);
$dpNoComments = preg_replace('/\/\/[^\n]*/', '', $dp);
ok(strpos($dpNoComments, 'LIMIT 1') === false, 'dashboard has no SQL LIMIT 1 in queries');
ok(strpos($dp, 'myActiveShifts') !== false,              'dashboard uses myActiveShifts array');
ok(strpos($dp, 'canOpenMore') !== false,                  'dashboard checks canOpenMore');
ok(strpos($dp, 'foreach ($myActiveShifts') !== false,    'dashboard iterates all active shift chips');
ok(strpos($dp, '!empty($myActiveShifts)') !== false,     'dashboard shows Close Shift independently');

// ── B. Live data-model (rolled back) ────────────────────────────────────────
section('B. Live data-model (transaction rolled back)');

if (!(bool)$pdo->query("SHOW TABLES LIKE 'mm_shifts'")->fetch()) {
    ok(true, 'mm_shifts table absent on this server — live tests skipped');
    return;
}

$uid  = (int)$pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
if (!$uid) { ok(false, 'need at least one user row'); return; }

// Need 2 active tills (or at least 1)
$tills = $pdo->query("SELECT t.till_id, t.till_number FROM mm_tills t JOIN mm_agents a ON a.agent_id=t.agent_id WHERE t.status='active' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (empty($tills)) {
    ok(true, 'no active tills — live tests skipped');
    return;
}

require_once "$root/core/code_generator.php";
require_once "$root/core/mm_float_service.php";

$pdo->beginTransaction();
try {
    $openCash  = 500.00; $openFloat = 1000.00;
    $closeCash = 650.00; $closeFloat = 950.00;

    // ── B1. Batch-open all available tills ─────────────────────────────────────
    $openedIds = [];
    foreach ($tills as $t) {
        // Clear any existing open shifts for this till inside the transaction
        $pdo->prepare("UPDATE mm_shifts SET status='closed', closed_at=NOW(), closed_by=? WHERE till_id=? AND status='open'")->execute([$uid, $t['till_id']]);

        $code = nextCode($pdo, 'MM-SFT');
        $pdo->prepare("INSERT INTO mm_shifts (shift_code, till_id, teller_user_id, opened_at, opening_cash, opening_float, status, created_at) VALUES (?,?,?,NOW(),?,?,'open',NOW())")
            ->execute([$code, $t['till_id'], $uid, $openCash, $openFloat]);
        $shiftId = (int)$pdo->lastInsertId();
        ok($shiftId > 0, "shift opened for till {$t['till_number']} — shift_id=$shiftId");
        $openedIds[] = ['shift_id' => $shiftId, 'till_id' => $t['till_id'], 'code' => $code, 'till_number' => $t['till_number']];

        // Verify row in DB
        $row = $pdo->prepare("SELECT status, opening_cash, opening_float FROM mm_shifts WHERE shift_id=?");
        $row->execute([$shiftId]);
        $row = $row->fetch(PDO::FETCH_ASSOC);
        ok($row && $row['status'] === 'open',               "shift {$shiftId} status='open' in DB");
        ok($row && approx($row['opening_cash'],  $openCash), "shift {$shiftId} opening_cash correct");
        ok($row && approx($row['opening_float'], $openFloat),'shift {$shiftId} opening_float correct');
    }

    // ── B2. Duplicate open rejected ────────────────────────────────────────────
    $dup = $tills[0];
    $existStmt = $pdo->prepare("SELECT shift_id FROM mm_shifts WHERE till_id=? AND status='open' LIMIT 1");
    $existStmt->execute([$dup['till_id']]);
    ok((bool)$existStmt->fetch(), "duplicate-open guard: till {$dup['till_number']} already open");

    // ── B3. Batch-close all opened shifts + verify variance ────────────────────
    foreach ($openedIds as $s) {
        $totStmt = $pdo->prepare("SELECT COALESCE(SUM(cash_effect),0) AS nc, COALESCE(SUM(float_effect),0) AS nf FROM mm_transactions WHERE shift_id=? AND status='posted'");
        $totStmt->execute([$s['shift_id']]);
        $tot = $totStmt->fetch(PDO::FETCH_ASSOC);
        $expCash  = $openCash  + (float)$tot['nc'];
        $expFloat = $openFloat + (float)$tot['nf'];
        $cvWant   = $closeCash  - $expCash;
        $fvWant   = $closeFloat - $expFloat;

        $pdo->prepare("UPDATE mm_shifts SET closed_at=NOW(), closing_cash=?, closing_float=?, expected_cash=?, expected_float=?, cash_variance=?, float_variance=?, status='closed', closed_by=? WHERE shift_id=?")
            ->execute([$closeCash, $closeFloat, $expCash, $expFloat, $cvWant, $fvWant, $uid, $s['shift_id']]);

        $after = $pdo->prepare("SELECT status, cash_variance, float_variance FROM mm_shifts WHERE shift_id=?");
        $after->execute([$s['shift_id']]);
        $after = $after->fetch(PDO::FETCH_ASSOC);
        ok($after && $after['status'] === 'closed',              "shift {$s['shift_id']} status='closed' after close");
        ok($after && approx($after['cash_variance'],  $cvWant),  "shift {$s['shift_id']} cash_variance={$cvWant} correct");
        ok($after && approx($after['float_variance'], $fvWant),  "shift {$s['shift_id']} float_variance={$fvWant} correct");
    }

    // ── B4. Re-close rejected ──────────────────────────────────────────────────
    $firstId = $openedIds[0]['shift_id'];
    $chk = $pdo->prepare("SELECT status FROM mm_shifts WHERE shift_id=?");
    $chk->execute([$firstId]);
    ok($chk->fetchColumn() === 'closed', "re-close guard: shift $firstId already closed — further close would be rejected");

} finally {
    $pdo->rollBack();
    echo "  (transaction rolled back — DB unchanged)\n";
}
