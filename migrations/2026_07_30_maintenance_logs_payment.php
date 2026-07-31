<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * 2026_07_30_maintenance_logs_payment.php
 * ------------------------------------------
 * post_principle.md gap fix — Maintenance. maintenance_logs already posts the
 * ACCRUAL correctly (Dr Expense / Cr Accrued Expenses at status=completed via
 * core/expense_posting.php's shared engine), but has no PAYMENT/settlement
 * step anywhere — the Accrued Expenses liability it raises has no
 * system-linked way to ever be cleared. This mirrors employee_trips exactly
 * (core/expense_posting.php's Trip wrappers + api/manage_trip.php), the
 * closest already-correct analog: accrue -> pay -> reverse.
 *
 * Adds:
 *   - paid_from_account_id, payment_date, paid_amount, transaction_id
 *     (identical shape to employee_trips' payment columns)
 *   - next_due_date (preserves the one real feature of the now-retired
 *     asset_maintenance table/asset_maintenance path — see the sibling
 *     migration 2026_07_30_remove_asset_maintenance.php)
 *   - widens status to include 'paid' (was pending/in_progress/completed/cancelled)
 *
 * Idempotent: every step checks existence/current definition first.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: maintenance_logs payment fields...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'maintenance_logs'")->fetch()) {
        echo "  ! maintenance_logs table not found — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $addCol = function (string $col, string $ddl) use ($pdo) {
        $exists = $pdo->query("SHOW COLUMNS FROM maintenance_logs LIKE " . $pdo->quote($col))->fetch();
        if ($exists) {
            echo "  = maintenance_logs.$col already exists — skipping.\n";
            return;
        }
        $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN $ddl");
        echo "  + maintenance_logs.$col added.\n";
    };

    $addCol('paid_from_account_id', "paid_from_account_id INT NULL AFTER expense_account_id");
    $addCol('payment_date',         "payment_date DATE NULL AFTER paid_from_account_id");
    $addCol('paid_amount',          "paid_amount DECIMAL(15,2) NULL AFTER payment_date");
    $addCol('transaction_id',       "transaction_id INT NULL AFTER paid_amount");
    $addCol('next_due_date',        "next_due_date DATE NULL AFTER completion_date");

    // Widen the status enum to include 'paid' and 'deleted' — idempotent,
    // checked against the live column definition rather than assumed.
    // 'deleted' backs a proper soft-delete (CLAUDE.md §12: never hard DELETE)
    // for delete_maintenance_log.php, which previously hard-deleted regardless
    // of whether a GL entry had been posted for the row.
    $col = $pdo->query("SHOW COLUMNS FROM maintenance_logs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && (stripos($col['Type'], "'paid'") === false || stripos($col['Type'], "'deleted'") === false)) {
        $pdo->exec("ALTER TABLE maintenance_logs
                    MODIFY COLUMN status ENUM('pending','in_progress','completed','paid','cancelled','deleted')
                    NOT NULL DEFAULT 'pending'");
        echo "  + status enum widened to include 'paid' and 'deleted'.\n";
    } else {
        echo "  = status enum already includes 'paid' and 'deleted' (or column missing) — skipping.\n";
    }

    // Index for the asset_dashboard.php overdue-maintenance widget (mirrors
    // the index asset_maintenance.next_due_date had).
    $idx = $pdo->query("SHOW INDEX FROM maintenance_logs WHERE Key_name = 'idx_ml_next_due'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE maintenance_logs ADD INDEX idx_ml_next_due (next_due_date)");
        echo "  + index idx_ml_next_due added.\n";
    } else {
        echo "  = index idx_ml_next_due already exists — skipping.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
