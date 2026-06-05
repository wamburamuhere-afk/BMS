<?php
/**
 * 2026_06_12_attendance_overtime.php
 * ----------------------------------
 * Plan H2 — attendance-driven payroll + overtime (foundation).
 *
 *  1. attendance.overtime_hours / overtime_amount — computed when a day is saved.
 *  2. payroll_settings:
 *       standard_hours_per_day = 8     (the shift standard overtime is measured against)
 *       payroll_attendance_mode = off  (FEATURE FLAG — default OFF keeps payroll exactly
 *                                        as today; switch to 'on' to let attendance drive
 *                                        per-day deductions + overtime)
 *
 * Additive + idempotent. With the flag off, nothing about payroll changes.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: attendance overtime columns + payroll settings...\n";

try {
    foreach (['overtime_hours', 'overtime_amount'] as $col) {
        if (!$pdo->query("SHOW COLUMNS FROM attendance LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN $col DECIMAL(10,2) NULL DEFAULT 0 AFTER total_hours");
            echo "  + attendance.$col added.\n";
        } else {
            echo "  = attendance.$col already exists.\n";
        }
    }

    $settings = [
        ['standard_hours_per_day', '8',  'Standard working hours per day (overtime is hours beyond this)'],
        ['payroll_attendance_mode', 'off', 'When on, payroll derives per-day deductions + overtime from attendance'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO payroll_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW())");
    foreach ($settings as $s) { $stmt->execute($s); echo "  + setting '{$s[0]}' ensured (= {$s[1]}).\n"; }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
