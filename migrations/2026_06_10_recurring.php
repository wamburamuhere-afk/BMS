<?php
/**
 * 2026_06_10_recurring.php
 * ------------------------
 * Plan C (Recurring Transactions) — foundation.
 *
 * Lets a repeating charge (rent, a retainer, a subscription) be defined ONCE as a
 * template + schedule; the system then auto-creates the real document each period.
 * v1 generates EXPENSES (created 'pending' — post-gated, so no money moves until
 * someone approves & marks them Paid). The engine is generic (doc_type +
 * template_json) so invoice / bill generators can be added later with no schema
 * change.
 *
 *  1. recurring_profiles — the template + schedule + run state.
 *  2. recurring_runs     — an audit trail of what each profile produced (also the
 *                          idempotency guard: never generate twice for one due date).
 *
 * Idempotent + additive — safe to re-run. No DDL inside a transaction.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: recurring (profiles + runs)...\n";

try {
    // ── 1. recurring_profiles ─────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurring_profiles (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            name             VARCHAR(150) NOT NULL,
            doc_type         ENUM('expense','invoice','bill') NOT NULL DEFAULT 'expense',
            template_json    LONGTEXT NOT NULL,
            frequency        ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
            interval_count   INT NOT NULL DEFAULT 1,
            start_date       DATE NOT NULL,
            next_run_date    DATE NOT NULL,
            end_date         DATE NULL,
            occurrences_left INT NULL,
            status           ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
            project_id       INT NULL,
            last_run_at      DATETIME NULL,
            created_by       INT NULL,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rp_due (status, next_run_date),
            KEY idx_rp_type (doc_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + recurring_profiles table ready.\n";

    // ── 2. recurring_runs ─────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurring_runs (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            profile_id         INT NOT NULL,
            run_for_date       DATE NOT NULL,
            generated_doc_type VARCHAR(20) NOT NULL,
            generated_doc_id   INT NULL,
            created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_profile_date (profile_id, run_for_date),
            KEY idx_rr_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + recurring_runs table ready (unique per profile+date = idempotent).\n";

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
