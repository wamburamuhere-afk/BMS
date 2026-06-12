<?php
/**
 * 2026_06_13_leave_balances.php
 * -----------------------------
 * Plan H3 — leave balance & entitlement ledger.
 *
 * leave_balances stores, per employee + leave type + year, the ENTITLEMENT and any
 * CARRIED-OVER days. "Used" is NOT stored — it is summed live from approved leaves so
 * the balance can never drift (available = entitled + carried_over − used). The
 * `leaves` enum and its table are left untouched (no destructive change).
 *
 * Additive + idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: leave_balances ledger...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leave_balances (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            employee_id   INT NOT NULL,
            leave_type_id INT NOT NULL,
            year          INT NOT NULL,
            entitled      DECIMAL(6,2) NOT NULL DEFAULT 0,
            carried_over  DECIMAL(6,2) NOT NULL DEFAULT 0,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_type_year (employee_id, leave_type_id, year),
            KEY idx_lb_emp (employee_id),
            KEY idx_lb_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + leave_balances table ready (unique per employee+type+year; 'used' is computed live).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
