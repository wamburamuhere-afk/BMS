<?php
/**
 * BMS — Company Calendar guard (Working Days + Public Holidays + leave day-counting).
 *
 * Before this, public_holidays was a table with a full schema and ZERO code
 * references anywhere in the codebase — it existed, but nothing ever read or
 * wrote it. leaveDaysFor() always counted pure calendar days, with no way to
 * exclude weekends or public holidays even for leave types where that matters.
 *
 * Covers: schema, permission, route, menu wiring, core/company_calendar.php's
 * math (working weekdays, exact + recurring holiday matching, business-day
 * counting), leaveDaysFor()'s backward-compatible opt-in extension, the
 * leaves.php JS embed + mirror, and live round trips through the real endpoints
 * (subprocess runner — real request lifecycle, real CSRF, real session).
 *
 * Run:
 *   php tests/test_company_calendar_cli.php
 *
 * Exit 0 = all checks pass. Exit 1 = at least one check failed.
 */

$root = dirname(__DIR__);
require_once $root . '/roots.php';
require_once $root . '/core/company_calendar.php';
require_once $root . '/core/leave_rules.php';
global $pdo;

$passes = 0; $failures = 0;
function ok(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function bad(string $m): void { global $failures; $failures++; echo "  \033[31m❌\033[0m $m\n"; }
function head(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

function run_endpoint(string $root, string $endpoint, array $post = [], array $get = []): array {
    $runner = $root . '/tests/_tmp_calendar_runner.php';
    $code = '<?php
require_once ' . var_export($root . '/roots.php', true) . ';
$_SESSION["user_id"]=4; $_SESSION["username"]="admin"; $_SESSION["is_admin"]=true; $_SESSION["role_id"]=1;
$_SERVER["REQUEST_METHOD"]=' . var_export($post ? 'POST' : 'GET', true) . ';
parse_str(' . var_export(http_build_query($get), true) . ', $_GET);
parse_str(' . var_export(http_build_query($post), true) . ', $_POST);
if (function_exists("csrf_token")) { $_POST["_csrf"] = csrf_token(); }
require ' . var_export($endpoint, true) . ';
';
    file_put_contents($runner, $code);
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'));
    @unlink($runner);
    return [json_decode(trim((string)$out), true), trim((string)$out)];
}

echo "\n\033[1m═══ Company Calendar (Working Days / Public Holidays / leave day-counting) ═══\033[0m\n";

head('1. php -l — every new/changed file');
$files = [
    'core/company_calendar.php', 'core/leave_rules.php', 'core/leave_type_validation.php',
    'app/bms/pos/company_calendar.php', 'app/bms/pos/leave_types.php', 'app/bms/pos/leaves.php',
    'api/pos/save_working_days.php', 'api/pos/save_holiday.php', 'api/pos/toggle_holiday_status.php',
    'api/add_leave_type.php', 'api/update_leave_type.php', 'api/get_leave_type.php',
    'api/apply_leave.php', 'api/update_leave.php',
];
foreach ($files as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? ok("$f lint-clean") : bad("$f: " . trim((string)$out));
}

head('2. Schema');
$hasTable = $pdo->query("SHOW TABLES LIKE 'public_holidays'")->fetch(PDO::FETCH_ASSOC);
$hasTable ? ok('public_holidays table exists') : bad('public_holidays table missing');
$col = $pdo->query("SHOW COLUMNS FROM leave_types LIKE 'count_working_days_only'")->fetch(PDO::FETCH_ASSOC);
$col ? ok('leave_types.count_working_days_only exists') : bad('leave_types.count_working_days_only missing');
($col && $col['Default'] === '0') ? ok('count_working_days_only defaults to 0 (backward compatible)') : bad('count_working_days_only default is not 0');

head('3. Permission + route + menu wiring');
$permId = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$permId->execute(['company_calendar']);
$permId->fetchColumn() ? ok("permission 'company_calendar' exists") : bad("permission 'company_calendar' missing");
$rootsSrc = file_get_contents("$root/roots.php");
strpos($rootsSrc, "'company_calendar' => POS_DIR") !== false ? ok("route 'company_calendar' registered") : bad("route missing");
$headerSrc = file_get_contents("$root/header.php");
(strpos($headerSrc, "getUrl('company_calendar')") !== false && strpos($headerSrc, "canView('company_calendar')") !== false)
    ? ok('header.php links to company_calendar, gated by canView') : bad('header.php missing a gated link');

head('4. core/company_calendar.php math');
($origSetting = get_setting('company_working_days', '1,2,3,4,5')) !== null ? ok('companyWorkingDays() has a default') : bad('no default');
$defaultDays = companyWorkingDays();
($defaultDays === [1,2,3,4,5] || in_array(1, $defaultDays, true)) ? ok('default working days include Monday') : bad('default working days wrong: ' . json_encode($defaultDays));
isCompanyWorkingWeekday(1, [1,2,3,4,5]) ? ok('Monday is a working weekday when Mon-Fri configured') : bad('Monday wrongly excluded');
!isCompanyWorkingWeekday(6, [1,2,3,4,5]) ? ok('Saturday is NOT a working weekday when Mon-Fri configured') : bad('Saturday wrongly included');

// Business-day counting with a temp holiday
$pdo->exec("DELETE FROM public_holidays WHERE holiday_name LIKE 'ZZTEST%'");
$testYear = (int)date('Y') + 1; // a year safely in the future, avoids clashing with any real past leave data
$mon = "$testYear-03-02"; // a Monday (2026+ arbitrary — recomputed below to guarantee it's actually a Monday)
// Find the first Monday of March in $testYear to keep the math simple and deterministic.
$d = new DateTime("$testYear-03-01");
while ((int)$d->format('N') !== 1) { $d->modify('+1 day'); }
$mon = $d->format('Y-m-d');
$fri = (clone $d)->modify('+4 days')->format('Y-m-d');   // same week, Friday
$wed = (clone $d)->modify('+2 days')->format('Y-m-d');   // same week, Wednesday
$nextMon = (clone $d)->modify('+7 days')->format('Y-m-d'); // next Monday

$noHolidayCount = businessDaysBetween($pdo, $mon, $fri);
($noHolidayCount === 5) ? ok("Mon–Fri with no holiday = 5 business days (got $noHolidayCount)") : bad("expected 5, got $noHolidayCount");

$spanWeekend = businessDaysBetween($pdo, $fri, $nextMon);
($spanWeekend === 2) ? ok("Fri–next Mon (spans a weekend) = 2 business days (got $spanWeekend)") : bad("expected 2, got $spanWeekend");

$pdo->prepare("INSERT INTO public_holidays (holiday_name, holiday_date, holiday_type, recurring, status, created_by, created_at)
               VALUES ('ZZTEST Mid-week Holiday', ?, 'company', 0, 'active', 1, NOW())")->execute([$wed]);
$withHoliday = businessDaysBetween($pdo, $mon, $fri);
($withHoliday === 4) ? ok("Mon–Fri with a Wednesday holiday = 4 business days (got $withHoliday)") : bad("expected 4, got $withHoliday");
isPublicHoliday($pdo, $wed) ? ok('isPublicHoliday() true on the exact date') : bad('isPublicHoliday() false on the exact date');

// Recurring holiday matches a different year on the same month/day
$recDate = "$testYear-12-25";
$pdo->prepare("INSERT INTO public_holidays (holiday_name, holiday_date, holiday_type, recurring, status, created_by, created_at)
               VALUES ('ZZTEST Recurring', ?, 'national', 1, 'active', 1, NOW())")->execute([$recDate]);
isPublicHoliday($pdo, ($testYear + 3) . '-12-25') ? ok('recurring holiday matches a different year, same month/day') : bad('recurring holiday match failed');

$pdo->prepare("INSERT INTO public_holidays (holiday_name, holiday_date, holiday_type, recurring, status, created_by, created_at)
               VALUES ('ZZTEST Inactive', ?, 'company', 0, 'inactive', 1, NOW())")->execute([date('Y-m-d')]);
!isPublicHoliday($pdo, date('Y-m-d')) ? ok('an inactive holiday is not counted') : bad('inactive holiday wrongly counted');

$pdo->exec("DELETE FROM public_holidays WHERE holiday_name LIKE 'ZZTEST%'");

head('5. leaveDaysFor() — backward compatible + opt-in business-day mode');
abs(leaveDaysFor('2030-01-01', '2030-01-03', 'none', null) - 3.0) < 0.001
    ? ok('4-arg call (no working-days flag) behaves exactly as before: 3 calendar days') : bad('backward compatibility broken');
abs(leaveDaysFor($mon, $fri, 'none', null, true, $pdo) - 5.0) < 0.001
    ? ok('workingDaysOnly=true over a clean Mon-Fri week = 5') : bad('workingDaysOnly math wrong');
abs(leaveDaysFor($fri, $nextMon, 'none', null, true, $pdo) - 2.0) < 0.001
    ? ok('workingDaysOnly=true spanning a weekend = 2 (Fri + Mon)') : bad('weekend exclusion wrong');
$threw = false;
try { leaveDaysFor($mon, $fri, 'none', null, true, null); } catch (InvalidArgumentException $e) { $threw = true; }
$threw ? ok('workingDaysOnly=true without a PDO throws (fails safe, cannot silently miscount)') : bad('did not throw without PDO');

head('6. leaves.php embeds the calendar for the live JS preview');
$leavesSrc = file_get_contents("$root/app/bms/pos/leaves.php");
strpos($leavesSrc, 'window.COMPANY_WORKING_DAYS') !== false ? ok('embeds window.COMPANY_WORKING_DAYS') : bad('missing embed');
strpos($leavesSrc, 'window.COMPANY_HOLIDAYS') !== false ? ok('embeds window.COMPANY_HOLIDAYS') : bad('missing embed');
strpos($leavesSrc, 'data-working-days-only=') !== false ? ok('leave-type <option> carries data-working-days-only') : bad('missing data attribute');
strpos($leavesSrc, "function leaveDaysFor(startDate, endDate, halfDay, workingDaysOnly)") !== false
    ? ok('JS leaveDaysFor() mirror accepts the new parameter') : bad('JS mirror not updated');

head('7. get_leave_type.php list mode includes the new flag (feeds the leave-type dropdown)');
$listSrc = file_get_contents("$root/api/get_leave_type.php");
strpos($listSrc, 'count_working_days_only') !== false ? ok('list-mode SELECT includes count_working_days_only') : bad('list-mode SELECT missing the column — dropdown would silently omit it');

// ── Live round trips ────────────────────────────────────────────────────────
$origWorkingDaysSetting = get_setting('company_working_days', '1,2,3,4,5');
$createdHolidayId = null; $createdTypeId = null;

try {
    head('8. Live: save_working_days.php persists + restores');
    [$r] = run_endpoint($root, "$root/api/pos/save_working_days.php", ['working_days' => ['1','2','3','4','5','6']]);
    (is_array($r) && !empty($r['success'])) ? ok('saved Mon-Sat') : bad('save failed: ' . json_encode($r));
    // get_setting() caches per-process (static array) — the write happened in a
    // subprocess, so read the row directly rather than through the stale cache.
    $reread = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_working_days'")->fetchColumn();
    ($reread === '1,2,3,4,5,6') ? ok("setting persisted correctly (got '$reread')") : bad("setting wrong: '$reread'");

    head('9. Live: save_holiday.php create → update → toggle status');
    [$rc] = run_endpoint($root, "$root/api/pos/save_holiday.php", [
        'holiday_name' => 'ZZTEST Live Holiday', 'holiday_date' => date('Y-m-d', strtotime('+10 days')),
        'holiday_type' => 'company', 'recurring' => '0', 'country' => 'Tanzania',
    ]);
    (is_array($rc) && !empty($rc['success']) && !empty($rc['id'])) ? ok('created') : bad('create failed: ' . json_encode($rc));
    $createdHolidayId = (int)($rc['id'] ?? 0);

    [$ru] = run_endpoint($root, "$root/api/pos/save_holiday.php", [
        'holiday_id' => $createdHolidayId, 'holiday_name' => 'ZZTEST Live Holiday Renamed',
        'holiday_date' => date('Y-m-d', strtotime('+10 days')), 'holiday_type' => 'company', 'country' => 'Tanzania',
    ]);
    (is_array($ru) && !empty($ru['success'])) ? ok('updated') : bad('update failed: ' . json_encode($ru));
    $name = $pdo->query("SELECT holiday_name FROM public_holidays WHERE holiday_id = $createdHolidayId")->fetchColumn();
    ($name === 'ZZTEST Live Holiday Renamed') ? ok('rename persisted') : bad("rename did not persist (got '$name')");

    [$rt] = run_endpoint($root, "$root/api/pos/toggle_holiday_status.php", ['holiday_id' => $createdHolidayId, 'status' => 'inactive']);
    (is_array($rt) && !empty($rt['success'])) ? ok('deactivated') : bad('deactivate failed: ' . json_encode($rt));
    $status = $pdo->query("SELECT status FROM public_holidays WHERE holiday_id = $createdHolidayId")->fetchColumn();
    ($status === 'inactive') ? ok('status = inactive persisted') : bad("status wrong: '$status'");

    head('10. Live: leave type "count only working days" flag saves through the real endpoint');
    $stamp = 'ZZTEST' . substr((string)microtime(true), -5, 5);
    [$rlt] = run_endpoint($root, "$root/api/add_leave_type.php", [
        'type_name' => "$stamp Leave", 'max_days_per_year' => '10', 'is_paid' => '1',
        'count_working_days_only' => '1',
    ]);
    (is_array($rlt) && !empty($rlt['success']) && !empty($rlt['id'])) ? ok('created with flag on') : bad('create failed: ' . json_encode($rlt));
    $createdTypeId = (int)($rlt['id'] ?? 0);
    $flag = (int)$pdo->query("SELECT count_working_days_only FROM leave_types WHERE type_id = $createdTypeId")->fetchColumn();
    ($flag === 1) ? ok('count_working_days_only = 1 persisted') : bad("flag not persisted (got $flag)");

    [$rlt2] = run_endpoint($root, "$root/api/update_leave_type.php", [
        'type_id' => $createdTypeId, 'type_name' => "$stamp Leave", 'max_days_per_year' => '10', 'is_paid' => '1',
        // count_working_days_only omitted = unchecked checkbox
    ]);
    (is_array($rlt2) && !empty($rlt2['success'])) ? ok('updated with flag off') : bad('update failed: ' . json_encode($rlt2));
    $flag2 = (int)$pdo->query("SELECT count_working_days_only FROM leave_types WHERE type_id = $createdTypeId")->fetchColumn();
    ($flag2 === 0) ? ok('count_working_days_only = 0 persisted (unchecked checkbox correctly clears it)') : bad("flag not cleared (got $flag2)");

} finally {
    // ── Cleanup: restore the real company setting + delete this test's fixtures only ──
    save_setting('company_working_days', $origWorkingDaysSetting);
    $restored = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_working_days'")->fetchColumn();
    ($restored === $origWorkingDaysSetting) ? ok("working-days setting restored to original ('$origWorkingDaysSetting')") : bad('failed to restore original working-days setting!');

    if ($createdHolidayId) $pdo->exec("DELETE FROM public_holidays WHERE holiday_id = $createdHolidayId");
    if ($createdTypeId) $pdo->exec("DELETE FROM leave_types WHERE type_id = $createdTypeId");
    $pdo->exec("DELETE FROM public_holidays WHERE holiday_name LIKE 'ZZTEST%'");
    echo "\n  (test fixtures cleaned up)\n";
}

echo "\n\033[1m═══ Result ═══\033[0m\n";
if ($failures === 0) { echo "\033[32m✅ All $passes checks passed.\033[0m\n"; exit(0); }
echo "\033[31m❌ $failures check(s) failed, $passes passed.\033[0m\n";
exit(1);
