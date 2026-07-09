<?php
/**
 * BMS — Leaves module upgrade guard.
 *
 * Covers the Phase 1-4 work:
 *   - leaves.leave_type_id FK + backfill (the ENUM/type_name join matched 0 rows)
 *   - a NEWLY ADDED leave type is actually storable (it used to collapse to 'other')
 *   - half_day + leave_hours persist; is_paid is snapshotted from the type
 *   - server-side enforcement of max_days_per_year / max_consecutive_days
 *   - leave_types delete guard (deactivate when used, delete when unused)
 *   - removing the Additional Notes field does not wipe existing notes
 *   - leave_types.php is not in the header nav
 *
 * Every row this test creates is removed again; it asserts the leaves/leave_types
 * counts return to their starting values.
 *
 * Run:  php tests/test_leaves_upgrade_cli.php
 * Exit 0 = pass, 1 = failure.
 */

require_once dirname(__DIR__) . '/roots.php';
require_once dirname(__DIR__) . '/core/leave_rules.php';
require_once dirname(__DIR__) . '/core/leave_type_validation.php';

$failures = 0;
$passes   = 0;

function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

/** Capture the thrown message, or null if nothing was thrown. */
function threw(callable $fn): ?string {
    try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "\n\033[1m═══ Leaves module upgrade ═══\033[0m\n";

$_SESSION['user_id'] = 1;

$leavesBefore = (int)$pdo->query("SELECT COUNT(*) FROM leaves")->fetchColumn();
$typesBefore  = (int)$pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn();
$cleanupLeaves = [];
$cleanupTypes  = [];

// ── Schema ────────────────────────────────────────────────────────────────
head('Schema — leaves is connected to leave_types');

foreach (['leave_type_id', 'half_day', 'leave_hours', 'is_paid', 'contact_during_leave', 'handover_to'] as $col) {
    $st = $pdo->prepare("SHOW COLUMNS FROM leaves LIKE ?");
    $st->execute([$col]);
    $st->fetch() ? ok("leaves.$col exists") : bad("leaves.$col is missing");
}

$fk = $pdo->query("
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leaves'
       AND CONSTRAINT_NAME = 'fk_leaves_leave_type' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetchColumn();
$fk ? ok('FK fk_leaves_leave_type is enforced') : bad('FK fk_leaves_leave_type is missing');

$td = $pdo->query("SHOW COLUMNS FROM leaves LIKE 'total_days'")->fetch(PDO::FETCH_ASSOC);
stripos($td['Type'], 'decimal') !== false
    ? ok("leaves.total_days is {$td['Type']} (fractional leave survives)")
    : bad("leaves.total_days is {$td['Type']} — fractional days truncate to 0");

head('Backfill — the old join matched nothing, the new one resolves');
$oldJoin = (int)$pdo->query("SELECT COUNT(*) FROM leaves l JOIN leave_types lt ON l.leave_type = lt.type_name")->fetchColumn();
$newJoin = (int)$pdo->query("SELECT COUNT(*) FROM leaves l JOIN leave_types lt ON lt.type_id = l.leave_type_id")->fetchColumn();
$oldJoin === 0 ? ok('the legacy name-join still matches 0 rows (as it always did)') : bad("legacy join unexpectedly matches $oldJoin");
$newJoin > 0   ? ok("the FK join resolves $newJoin row(s)") : bad('the FK join resolves no rows');

$wrong = (int)$pdo->query("
    SELECT COUNT(*) FROM leaves l JOIN leave_types lt ON lt.type_id = l.leave_type_id
     WHERE LOWER(SUBSTRING_INDEX(lt.type_name,' ',1)) <> LOWER(l.leave_type)")->fetchColumn();
$wrong === 0 ? ok('no backfilled row points at the wrong type') : bad("$wrong row(s) point at the wrong type");

// ── A brand-new leave type must be usable ─────────────────────────────────
head('A newly added leave type is storable (the original bug)');

$newTypeId = null;
$msg = threw(function () use ($pdo, &$newTypeId) {
    $data = validateLeaveTypeInput($pdo, [
        'type_name' => '__TEST Bereavement Leave',
        'max_days_per_year' => 6,
        'max_consecutive_days' => 3,
        'is_paid' => '1',
    ], null);
    $pdo->prepare("INSERT INTO leave_types (type_name, max_days_per_year, min_days_before_apply, max_consecutive_days, requires_document, is_paid, carry_over_days, color, status, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$data['type_name'], $data['max_days_per_year'], $data['min_days_before_apply'], $data['max_consecutive_days'],
                   $data['requires_document'], $data['is_paid'], $data['carry_over_days'], $data['color'], $data['status'], 1]);
    $newTypeId = (int)$pdo->lastInsertId();
});
$msg === null && $newTypeId ? ok("created leave type #$newTypeId") : bad("could not create leave type: $msg");
if ($newTypeId) $cleanupTypes[] = $newTypeId;

// 'Bereavement' has no ENUM member — before the FK this leave was stored as 'other'
// and the type was lost.
if ($newTypeId) {
    $type = leaveTypeForApply($pdo, $newTypeId);
    legacyLeaveTypeEnum($type) === 'other'
        ? ok("legacy ENUM falls back to 'other' (why the FK was needed)")
        : bad('unexpected legacy ENUM mapping');

    $employee_id = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO leaves (employee_id, leave_type_id, leave_type, start_date, end_date, total_days, days_count, half_day, leave_hours, is_paid, reason, status, created_by, applied_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',1,1,NOW())")
        ->execute([$employee_id, $newTypeId, 'other', '2030-05-01', '2030-05-02', 2, 2, 'none', null, (int)$type['is_paid'], '__TEST bereavement']);
    $lid = (int)$pdo->lastInsertId();
    $cleanupLeaves[] = $lid;

    $row = $pdo->query("SELECT lt.type_name FROM leaves l JOIN leave_types lt ON lt.type_id = l.leave_type_id WHERE l.leave_id = $lid")->fetchColumn();
    $row === '__TEST Bereavement Leave'
        ? ok('the leave resolves back to the new type by name')
        : bad("the leave resolved to '" . var_export($row, true) . "'");
}

// ── Half day ──────────────────────────────────────────────────────────────
// The hourly-leave feature (Half Day = "Other (specify)…") was removed —
// never used in production (0 rows), and it looked like the free-text
// "Other" pattern from employee registration but was actually a numeric
// hours field, which confused users. 'other' is no longer a valid selection.
head('Half Day — "Other (specify)" is no longer offered');

$m = threw(fn() => normaliseHalfDay(['half_day' => 'other']));
$m && str_contains($m, 'Invalid half-day selection') ? ok("'other' is rejected as an invalid half-day selection") : bad("expected a rejection, got: " . var_export($m, true));

$hd = normaliseHalfDay(['half_day' => 'none', 'leave_hours' => 3.5]);
$hd['leave_hours'] === null ? ok('leave_hours is always null now (feature removed)') : bad('stale hours kept');

// 'none' must not silently dock half a day.
abs(leaveDaysFor('2030-01-01', '2030-01-03', 'none', null) - 3.0) < 0.001 ? ok("half_day='none' consumes full days") : bad("half_day='none' altered the day count");
abs(leaveDaysFor('2030-01-01', '2030-01-03', 'first_half', null) - 2.5) < 0.001 ? ok('first_half consumes 2.5 of 3 days') : bad('first_half maths wrong');

// ── Server-side limits ────────────────────────────────────────────────────
head('Type limits are enforced server-side');

if ($newTypeId) {
    $type = leaveTypeForApply($pdo, $newTypeId);   // 6/yr, max 3 consecutive
    $employee_id = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();

    $m = threw(fn() => assertLeaveWithinTypeLimits($pdo, $type, $employee_id, '2031-01-01', 4.0));
    $m && str_contains($m, 'consecutive') ? ok('max_consecutive_days is enforced') : bad("consecutive limit not enforced (got: " . var_export($m, true) . ")");

    $m = threw(fn() => assertLeaveWithinTypeLimits($pdo, $type, $employee_id, '2031-01-01', 3.0));
    $m === null ? ok('a request within the limits passes') : bad("a valid request was rejected: $m");
}

$m = threw(fn() => leaveTypeForApply($pdo, 999999));
$m && str_contains($m, 'no longer exists') ? ok('an unknown/inactive type is refused') : bad('unknown type accepted');

// ── Delete guard ──────────────────────────────────────────────────────────
head('Leave type delete guard');

if ($newTypeId) {
    leaveTypeUsageCount($pdo, $newTypeId) === 1
        ? ok('usage count sees the booked leave')
        : bad('usage count is wrong');

    // FK is ON DELETE RESTRICT: a used type cannot be hard-deleted even by SQL.
    $m = threw(fn() => $pdo->prepare("DELETE FROM leave_types WHERE type_id = ?")->execute([$newTypeId]));
    $m !== null ? ok('the FK blocks deleting a type that has leaves') : bad('a used type was hard-deleted');
}

// ── Notes preservation ────────────────────────────────────────────────────
head('Removing the Additional Notes field does not wipe stored notes');

// Strip comments first: the file explains in prose why it must not write
// `notes = ?`, and a naive grep would match that explanation.
$src = @file_get_contents(dirname(__DIR__) . '/api/update_leave.php') ?: '';
$srcNoComments = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $src);
!preg_match('/\bnotes\s*=\s*\?/', $srcNoComments)
    ? ok('update_leave.php no longer writes the notes column')
    : bad('update_leave.php still writes notes — editing a leave would blank it');

$formSrc = @file_get_contents(dirname(__DIR__) . '/app/bms/pos/leaves.php') ?: '';
strpos($formSrc, 'name="notes"') === false ? ok('the Additional Notes field is gone from the form') : bad('the Additional Notes field is still present');
strpos($formSrc, 'name="is_paid"') === false ? ok('the Paid/Unpaid selector is gone from the leave form') : bad('the Paid/Unpaid selector is still on the leave form');
strpos($formSrc, 'name="leave_type_id"') !== false ? ok('the leave form posts leave_type_id') : bad('the leave form does not post leave_type_id');
strpos($formSrc, 'Other (specify)') === false ? ok('Half Day no longer offers "Other (specify)" (hourly leave removed)') : bad('Half Day still offers "Other (specify)"');
strpos($formSrc, "getUrl('leave_types')") !== false ? ok('the leave form links to the leave types page') : bad('the manage-leave-types link is missing');

// ── The management page must not be in the header nav ─────────────────────
head('Leave Types page is reachable only from the leave form');

$navSrc = @file_get_contents(dirname(__DIR__) . '/header.php') ?: '';
strpos($navSrc, 'leave_types') === false
    ? ok('leave_types does not appear in header.php')
    : bad('leave_types is linked from the header nav');

file_exists(dirname(__DIR__) . '/app/bms/pos/leave_types.php') ? ok('leave_types.php exists') : bad('leave_types.php is missing');
(int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'leave_types'")->fetchColumn() === 1
    ? ok("the 'leave_types' permission key is seeded")
    : bad("the 'leave_types' permission key is missing");

// ── Other writers carry the FK ────────────────────────────────────────────
head('Every writer of the leaves table sets leave_type_id');

foreach (['api/apply_leave.php', 'api/my_leave_apply.php', 'api/import_leaves.php', 'api/operations/save_project_leave.php'] as $rel) {
    $s = @file_get_contents(dirname(__DIR__) . '/' . $rel) ?: '';
    strpos($s, 'leave_type_id') !== false ? ok("$rel sets leave_type_id") : bad("$rel inserts without leave_type_id");
}
leaveTypeIdForEnum($pdo, 'annual') === 1 ? ok("leaveTypeIdForEnum('annual') resolves to Annual Leave") : bad('enum→id resolution is wrong');
leaveTypeIdForEnum($pdo, 'other') === null ? ok("leaveTypeIdForEnum('other') is null (renders as an em dash)") : bad("'other' unexpectedly resolved");

// ── UI standard (.claude/ui-constants.md §UI-1) ───────────────────────────
head('UI standard — blue scale, no green/amber chrome');

$detailsSrc = @file_get_contents(dirname(__DIR__) . '/app/bms/pos/leave_details.php') ?: '';
foreach (['#198754' => 'green gradient/accent', '#157347' => 'dark green', '#d1e7dd' => 'green background',
          'btn-success' => 'green button', 'btn-outline-success' => 'green outline button',
          'btn-warning' => 'amber button', '#ffc107' => 'amber confirm'] as $needle => $what) {
    strpos($detailsSrc, $needle) === false
        ? ok("leave_details.php has no $what")
        : bad("leave_details.php still uses $what ($needle)");
}

$leavesSrc = @file_get_contents(dirname(__DIR__) . '/app/bms/pos/leaves.php') ?: '';
preg_match_all('/modal-header bg-(\w+)/', $leavesSrc, $mh);
$offStandard = array_values(array_diff(array_unique($mh[1]), ['primary']));
empty($offStandard)
    ? ok('every modal header in leaves.php is bg-primary')
    : bad('off-standard modal header(s): bg-' . implode(', bg-', $offStandard));

// The old print page is still routed; it must resolve the type through the FK,
// or it prints 'Other' for types with no ENUM member.
$appSrc = @file_get_contents(dirname(__DIR__) . '/app/bms/pos/leave_application.php') ?: '';
strpos($appSrc, 'lt.type_id = l.leave_type_id') !== false
    ? ok('leave_application.php resolves the type through the FK')
    : bad('leave_application.php still prints the raw ENUM');

// ── Cleanup ───────────────────────────────────────────────────────────────
head('Cleanup');
foreach ($cleanupLeaves as $id) $pdo->exec("DELETE FROM leaves WHERE leave_id = $id");
foreach ($cleanupTypes  as $id) $pdo->exec("DELETE FROM leave_types WHERE type_id = $id");

$leavesAfter = (int)$pdo->query("SELECT COUNT(*) FROM leaves")->fetchColumn();
$typesAfter  = (int)$pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn();
$leavesAfter === $leavesBefore ? ok("leaves restored to $leavesBefore row(s)") : bad("leaves left at $leavesAfter, expected $leavesBefore");
$typesAfter  === $typesBefore  ? ok("leave_types restored to $typesBefore row(s)") : bad("leave_types left at $typesAfter, expected $typesBefore");

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
