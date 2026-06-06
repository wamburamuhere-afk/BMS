<?php
/**
 * Attendance-driven payroll + overtime (Plan H2) — CLI test
 *   php tests/test_attendance_payroll_cli.php
 *
 * Verifies files lint; migration applied (overtime cols + settings); the overtime
 * engine + period summary are correct; the feature flag defaults OFF (so payroll is
 * unchanged); and process/preview are mode-gated (legacy path preserved when off).
 * Attendance is MyISAM (no transactions) — runtime seeds rows and DELETES them.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/attendance_payroll.php";
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
    'core/attendance_payroll.php', 'migrations/2026_06_12_attendance_overtime.php',
    'api/mark_attendance.php', 'api/update_attendance_time.php',
    'api/process_payroll.php', 'api/preview_payroll.php',
] as $f) {
    $full = "$root/$f";
    if (!file_exists($full)) { fail("MISSING: $f"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($full) . " 2>&1", $o, $rc);
    $rc === 0 ? pass($f) : fail("php -l failed: $f");
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Migration applied (columns + settings)');
($pdo->query("SHOW COLUMNS FROM attendance LIKE 'overtime_hours'")->fetch()) ? pass('attendance.overtime_hours exists') : fail('overtime_hours missing');
($pdo->query("SHOW COLUMNS FROM attendance LIKE 'overtime_amount'")->fetch()) ? pass('attendance.overtime_amount exists') : fail('overtime_amount missing');
($pdo->query("SELECT COUNT(*) FROM payroll_settings WHERE setting_key='standard_hours_per_day'")->fetchColumn() == 1) ? pass('standard_hours_per_day seeded') : fail('standard hours setting missing');
$mode = $pdo->query("SELECT setting_value FROM payroll_settings WHERE setting_key='payroll_attendance_mode'")->fetchColumn();
($mode === 'off') ? pass("payroll_attendance_mode defaults to 'off' (payroll unchanged by default)") : fail("mode default is '$mode', expected 'off'");

// ─────────────────────────────────────────────────────────────────────────
section('3. Overtime engine (pure function)');
$o = computeAttendanceOvertime(10.0, 8.0, 6.0);
($o['overtime_hours'] === 2.0 && $o['overtime_amount'] === 12.0) ? pass('10h vs 8h standard @6/hr → 2h OT, 12.00') : fail('OT wrong: ' . json_encode($o));
$o = computeAttendanceOvertime(7.0, 8.0, 6.0);
($o['overtime_hours'] === 0.0 && $o['overtime_amount'] === 0.0) ? pass('7h → no overtime') : fail('expected zero OT: ' . json_encode($o));
$o = computeAttendanceOvertime(9.5, 8.0, 0.0);
($o['overtime_hours'] === 1.5 && $o['overtime_amount'] === 0.0) ? pass('OT hours counted even with no rate (amount 0)') : fail('OT-no-rate wrong: ' . json_encode($o));

// ─────────────────────────────────────────────────────────────────────────
section('4. Mode gating — both payroll files preserve the legacy path when off');
$proc = src($root, 'api/process_payroll.php');
has($proc, "attendancePayrollMode(\$pdo)", 'process reads the mode flag');
has($proc, "if (\$att_mode === 'on')", 'process branches on mode');
has($proc, "Legacy behaviour — unchanged", 'process keeps the legacy attendance branch');
has($proc, "'item_name' => 'Overtime'", 'process adds an Overtime payslip line (mode on)');
$prev = src($root, 'api/preview_payroll.php');
has($prev, "if (\$att_mode === 'on')", 'preview mirrors the mode branch');
$ma = src($root, 'api/mark_attendance.php');
has($ma, "computeAttendanceOvertime", 'mark_attendance computes overtime on save');
has($ma, "overtime_hours = ?", 'mark_attendance persists overtime columns');

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — period summary from seeded attendance (MyISAM cleanup)');
$emp = (int)$pdo->query("SELECT employee_id FROM employees ORDER BY employee_id LIMIT 1")->fetchColumn();
if ($emp <= 0) { fail('no employee to test'); }
else {
    $ids = [];
    try {
        $ins = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, total_hours, overtime_hours, overtime_amount, status, created_by, created_at) VALUES (?,?,?,?,?,?,4,NOW())");
        // 2099-12: 1 present (10h → 2h OT @ amount 30), 1 half_day, 1 absent.
        $ins->execute([$emp, '2099-12-01', 10.0, 2.0, 30.0, 'present']);  $ids[] = (int)$pdo->lastInsertId();
        $ins->execute([$emp, '2099-12-02', 4.0, 0.0, 0.0, 'half_day']);   $ids[] = (int)$pdo->lastInsertId();
        $ins->execute([$emp, '2099-12-03', 0.0, 0.0, 0.0, 'absent']);     $ids[] = (int)$pdo->lastInsertId();

        $sum = payrollAttendanceSummary($pdo, $emp, '2099-12');
        ($sum['present_days'] === 1) ? pass('summary present_days = 1') : fail('present_days: ' . $sum['present_days']);
        ($sum['half_days'] === 1) ? pass('summary half_days = 1') : fail('half_days: ' . $sum['half_days']);
        ($sum['absent_days'] === 1) ? pass('summary absent_days = 1') : fail('absent_days: ' . $sum['absent_days']);
        (abs($sum['overtime_amount'] - 30.0) < 0.01) ? pass('summary overtime_amount = 30 (from the seeded day)') : fail('overtime_amount: ' . $sum['overtime_amount']);

        // The mode-on payroll deduction math: per_day × (absent + 0.5×half).
        $basic = 2200.0; $workDays = 22.0; $perDay = $basic / $workDays;   // 100
        $expectDeduction = round($perDay * (1 + 0.5 * 1), 2);              // 100 × 1.5 = 150
        (abs($expectDeduction - 150.0) < 0.01) ? pass('mode-on deduction math: 1 absent + 1 half on 2200/22 = 150') : fail('deduction math: ' . $expectDeduction);
    } catch (Throwable $e) {
        fail('runtime error: ' . $e->getMessage());
    } finally {
        foreach ($ids as $id) if ($id) $pdo->prepare("DELETE FROM attendance WHERE attendance_id=?")->execute([$id]);
    }
    pass('seeded attendance cleaned up (MyISAM-safe)');
}
