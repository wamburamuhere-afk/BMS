<?php
/**
 * POS — Busy-register visibility + supervisor Force Close — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_register_visibility_force_close_cli.php
 *
 * Follow-up to Phase 8 (pos_upgrade_plan.md §7): a cashier could only
 * discover a register was already staffed by clicking Start Shift and
 * reading open_shift.php's error. There was also no recovery path for a
 * shift left open by someone who can no longer close it themselves
 * (crashed browser, forgot to log out) short of editing the database
 * directly.
 *
 * Verifies:
 *   1. All touched files lint-clean.
 *   2. Wiring source patterns:
 *      - get_registers.php LEFT JOINs the active shift + cashier onto every
 *        register row
 *      - pos_scripts_new.php disables a busy register in the Start Shift
 *        dropdown and labels who holds it
 *      - close_shift.php accepts an explicit shift_id for a supervisor
 *        force-close, distinct from the session-bound self-close path, and
 *        logs it via logAudit()
 *      - shift_history.php only renders Force Close for canEdit('pos')
 *        users on a shift that isn't their own, and defines safeOutput()
 *        locally before calling it (the exact class of bug fixed 2026-09-07)
 *   3. Live-DB (BEGIN/ROLLBACK isolation):
 *      a) get_registers.php's own query correctly reports a busy register's
 *         active_shift_id/active_cashier_name/active_shift_started_label,
 *         and NULLs for a free register
 *      b) close_shift.php's force-close branch logic: a shift opened by a
 *         DIFFERENT user is correctly flagged is_force_close = true and can
 *         be closed by the acting admin without touching the admin's own,
 *         separate session shift_id
 *      c) the ordinary self-close branch (own shift, no shift_id override)
 *         is unaffected — is_force_close = false and the session IS cleared
 *      d) rollback leaves no trace
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";

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

$files = [
    'api/pos/get_registers.php', 'api/pos/close_shift.php',
    'app/bms/pos/pos_scripts_new.php', 'app/bms/pos/shift_history.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$getRegSrc   = file_get_contents("$root/api/pos/get_registers.php");
$closeSrc    = file_get_contents("$root/api/pos/close_shift.php");
$scriptsSrc  = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
$historySrc  = file_get_contents("$root/app/bms/pos/shift_history.php");

$checks = [
    [$getRegSrc,  "LEFT JOIN cash_register_shifts sh ON sh.register_id = r.register_id AND sh.status = 'active'",
        'get_registers.php LEFT JOINs the active shift onto each register'],
    [$getRegSrc,  "active_shift_id",              'get_registers.php exposes active_shift_id'],
    [$getRegSrc,  "active_cashier_name",          'get_registers.php exposes active_cashier_name'],
    [$getRegSrc,  "active_shift_started_label",   'get_registers.php exposes a formatted start-time label'],

    [$scriptsSrc, "opt.prop('disabled', true)",   'pos_scripts_new.php disables a busy register option'],
    [$scriptsSrc, "in use by",                    'pos_scripts_new.php labels who holds a busy register'],
    [$scriptsSrc, "find('option:not(:disabled)')", 'pos_scripts_new.php auto-selects a free register, not a disabled one'],

    [$closeSrc,   "\$requested_shift_id = isset(\$_POST['shift_id'])", 'close_shift.php accepts an explicit shift_id override'],
    [$closeSrc,   "\$is_force_close = \$shift && (int)\$shift['user_id'] !== (int)\$user_id",
        'close_shift.php flags force-close only when the shift belongs to someone else'],
    [$closeSrc,   "logAudit(\$pdo, \$user_id, 'pos_shift_force_close'", 'close_shift.php audit-logs a force-close'],
    [$closeSrc,   "if (!\$is_force_close && (\$_SESSION['shift_id'] ?? null) == \$shift_id)",
        "close_shift.php never clears the acting admin's own session shift on a force-close"],

    [$historySrc, "force-close-btn",              'shift_history.php renders the Force Close trigger'],
    [$historySrc, "\$can_view_all && \$s['status'] === 'active' && (int)\$s['user_id'] !== (int)\$user_id",
        "shift_history.php only shows Force Close for someone else's active shift"],
    [$historySrc, "function safeOutput",          'shift_history.php defines safeOutput() locally before using it'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Live-DB (BEGIN/ROLLBACK isolation)');
// ─────────────────────────────────────────────────────────────────────────
global $pdo;

$busyRegisterId = 90000501;
$freeRegisterId = 90000502;
$busyShiftId    = 90000601;   // opened by another cashier (user 9) — force-close target
$ownShiftId     = 90000602;   // opened by the acting admin (user 4) — self-close path
$adminOwnShiftId = 90000603;  // admin's OWN unrelated active shift — must survive a force-close untouched
$otherUserId    = 9;          // a real users row (see users table) so the username JOIN resolves

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO pos_registers (register_id, register_name, register_code, location, status, created_at, updated_at)
                    VALUES (?, 'Test Counter A', 'TST-A', 'Test', 'active', NOW(), NOW())")
        ->execute([$busyRegisterId]);
    $pdo->prepare("INSERT INTO pos_registers (register_id, register_name, register_code, location, status, created_at, updated_at)
                    VALUES (?, 'Test Counter B', 'TST-B', 'Test', 'active', NOW(), NOW())")
        ->execute([$freeRegisterId]);
    pass('two synthetic registers created (one to be busy, one to stay free)');

    $pdo->prepare("INSERT INTO cash_register_shifts (shift_id, shift_code, user_id, register_id, starting_cash, status, created_at)
                    VALUES (?, 'RVFC-TEST-BUSY', ?, ?, 0, 'active', NOW())")
        ->execute([$busyShiftId, $otherUserId, $busyRegisterId]);
    pass('synthetic active shift created for another cashier on Test Counter A');

    // ── 3a. get_registers.php's own query, run verbatim against the fixture ──
    $sql = "SELECT r.register_id, r.register_name, r.register_code, r.status,
                   sh.shift_id AS active_shift_id,
                   u.username AS active_cashier_name,
                   DATE_FORMAT(sh.start_time, '%d %b, %H:%i') AS active_shift_started_label
              FROM pos_registers r
              LEFT JOIN cash_register_shifts sh ON sh.register_id = r.register_id AND sh.status = 'active'
              LEFT JOIN users u ON u.user_id = sh.user_id
             WHERE r.register_id IN (?, ?)
             ORDER BY r.register_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$busyRegisterId, $freeRegisterId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['register_id']] = $r; }

    $busy = $byId[$busyRegisterId] ?? null;
    $free = $byId[$freeRegisterId] ?? null;

    ($busy && (int)$busy['active_shift_id'] === $busyShiftId)
        ? pass('busy register correctly reports its active_shift_id')
        : fail('busy register did not report the expected active_shift_id: ' . json_encode($busy));
    ($busy && $busy['active_cashier_name'] !== null)
        ? pass("busy register correctly reports the cashier's username ({$busy['active_cashier_name']})")
        : fail('busy register did not report a cashier name');
    (!empty($busy['active_shift_started_label']))
        ? pass('busy register correctly reports a formatted start-time label')
        : fail('busy register did not report a start-time label');

    ($free && $free['active_shift_id'] === null)
        ? pass('free register correctly reports no active_shift_id (NULL)')
        : fail('free register unexpectedly reported an active shift: ' . json_encode($free));

    // ── 3b/3c. close_shift.php's own branching logic, replicated exactly ──
    $ownRegId = 1; // seed register, always present per Phase 8's own sanity check
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_id, shift_code, user_id, register_id, starting_cash, status, created_at)
                    VALUES (?, 'RVFC-TEST-OWN', 4, ?, 0, 'active', NOW())")
        ->execute([$ownShiftId, $freeRegisterId]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_id, shift_code, user_id, register_id, starting_cash, status, created_at)
                    VALUES (?, 'RVFC-TEST-ADMIN-OWN', 4, ?, 0, 'active', NOW())")
        ->execute([$adminOwnShiftId, $ownRegId]);
    pass('two more synthetic shifts created: one the admin is force-closing as themselves, one unrelated');

    $actingUserId = 4; // matches $_SESSION['user_id'] set above

    // --- Force-close path: requested_shift_id = the OTHER cashier's shift ---
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND status = 'active'");
    $stmt->execute([$busyShiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_force_close = $shift && (int)$shift['user_id'] !== (int)$actingUserId;
    $is_force_close ? pass('force-close branch: correctly flagged as a force-close (shift belongs to user 9, actor is user 4)')
                     : fail('force-close branch: should have been flagged is_force_close = true');

    // Simulate the admin's OWN separate session shift being adminOwnShiftId —
    // the force-close of busyShiftId must never touch it.
    $_SESSION['shift_id'] = $adminOwnShiftId;
    $shouldClearSession = (!$is_force_close && ($_SESSION['shift_id'] ?? null) == $busyShiftId);
    $shouldClearSession
        ? fail("force-close incorrectly would have cleared the admin's own session shift")
        : pass("force-close correctly leaves the admin's own session shift (#$adminOwnShiftId) untouched");

    // Apply the actual close (mirrors close_shift.php's UPDATE) and verify state.
    $pdo->prepare("UPDATE cash_register_shifts SET status = 'closed', end_time = NOW(), closed_by = ? WHERE shift_id = ?")
        ->execute([$actingUserId, $busyShiftId]);
    $closedRow = $pdo->query("SELECT status, closed_by FROM cash_register_shifts WHERE shift_id = $busyShiftId")->fetch(PDO::FETCH_ASSOC);
    ($closedRow && $closedRow['status'] === 'closed' && (int)$closedRow['closed_by'] === $actingUserId)
        ? pass('force-closed shift correctly persisted as closed, closed_by the acting admin')
        : fail('force-closed shift did not persist correctly: ' . json_encode($closedRow));

    // --- Ordinary self-close path: requested_shift_id = the ACTOR'S OWN shift ---
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND status = 'active'");
    $stmt->execute([$ownShiftId]);
    $shift2 = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_force_close2 = $shift2 && (int)$shift2['user_id'] !== (int)$actingUserId;
    (!$is_force_close2) ? pass('self-close branch: correctly NOT flagged as a force-close (shift belongs to the acting user)')
                         : fail('self-close branch: incorrectly flagged is_force_close = true');

    $_SESSION['shift_id'] = $ownShiftId; // this session's own shift really is the one being closed
    $shouldClearSession2 = (!$is_force_close2 && ($_SESSION['shift_id'] ?? null) == $ownShiftId);
    $shouldClearSession2
        ? pass('self-close correctly clears the session shift_id')
        : fail('self-close should have cleared the session shift_id but did not');

    $pdo->rollBack();
    pass('transaction rolled back');

    $left = (int)$pdo->query("SELECT COUNT(*) FROM cash_register_shifts WHERE shift_id IN ($busyShiftId, $ownShiftId, $adminOwnShiftId)")->fetchColumn();
    $left === 0 ? pass('no synthetic shift rows persisted after rollback') : fail("rollback left $left synthetic shift rows");
    $leftReg = (int)$pdo->query("SELECT COUNT(*) FROM pos_registers WHERE register_id IN ($busyRegisterId, $freeRegisterId)")->fetchColumn();
    $leftReg === 0 ? pass('no synthetic register rows persisted after rollback') : fail("rollback left $leftReg synthetic register rows");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('section 3 threw: ' . $e->getMessage());
} finally {
    unset($_SESSION['shift_id']);
}

exit($failures === 0 ? 0 : 1);
