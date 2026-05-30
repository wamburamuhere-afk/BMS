<?php
/**
 * 2026_05_29_period_closing.php
 * -----------------------------
 * Period-closing foundation.
 *
 * The closing step transfers the period's Net Profit / (Loss) — the balance
 * of every temporary account (Revenue, Expense, COGS) — into equity, then
 * leaves only the permanent accounts (Assets, Liabilities, Equity) carrying
 * forward. That post-closing set IS the Balance Sheet / next period's openings.
 *
 * This migration creates the two things the close needs and the codebase
 * does not yet have:
 *
 *   1. A `Retained Earnings` equity account — the destination for the
 *      accumulated profit/loss. (Only "Opening Balance Equity" existed.)
 *
 *   2. `accounting_periods` — records each close (period_end, net result,
 *      the closing journal entry id, who/when) and, via a UNIQUE key on
 *      period_end, prevents a period being closed twice.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS; the Retained Earnings account is
 * inserted only if an account of that name does not already exist.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: period closing foundation...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ! accounts table missing — cannot proceed.\n";
        exit(1);
    }

    // ── 1. accounting_periods tracking table ──────────────────────────────
    if ($pdo->query("SHOW TABLES LIKE 'accounting_periods'")->fetch()) {
        echo "  · accounting_periods already exists, skipping CREATE.\n";
    } else {
        $pdo->exec("
            CREATE TABLE `accounting_periods` (
                `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `period_end`                    DATE NOT NULL COMMENT 'Last day of the closed period',
                `status`                        ENUM('closed') NOT NULL DEFAULT 'closed',
                `total_revenue`                 DECIMAL(15,2) NOT NULL DEFAULT 0,
                `total_expense`                 DECIMAL(15,2) NOT NULL DEFAULT 0,
                `net_profit`                    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '+ profit, - loss',
                `closing_entry_id`              INT NULL COMMENT 'journal_entries.entry_id of the closing entry',
                `retained_earnings_account_id`  INT NULL,
                `closed_by`                     INT NULL,
                `closed_at`                     TIMESTAMP NULL,
                `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_period_end` (`period_end`),
                KEY `ix_ap_entry` (`closing_entry_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='Period closing log — one row per closed period'
        ");
        echo "  + created table accounting_periods.\n";
    }

    // ── 2. Retained Earnings equity account ───────────────────────────────
    $re = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Retained Earnings' LIMIT 1")->fetchColumn();
    if ($re) {
        echo "  · Retained Earnings account already exists (#{$re}), skipping.\n";
    } else {
        // Find the equity account_type id (classification-aware, not hard-coded).
        $equityTypeId = $pdo->query("SELECT type_id FROM account_types WHERE category = 'equity' ORDER BY type_id LIMIT 1")->fetchColumn();
        if (!$equityTypeId) {
            echo "  ! no equity account_type found — cannot create Retained Earnings.\n";
            exit(1);
        }

        // Pick a non-colliding account_code.
        $code = '3200';
        $exists = $pdo->prepare("SELECT 1 FROM accounts WHERE account_code = ? LIMIT 1");
        $exists->execute([$code]);
        if ($exists->fetchColumn()) {
            $code = 'RE-' . date('His');
        }

        $ins = $pdo->prepare("
            INSERT INTO accounts
                (account_code, account_name, account_type_id, account_type,
                 opening_balance, current_balance, status, created_at)
            VALUES (?, 'Retained Earnings', ?, 'equity', 0, 0, 'active', NOW())
        ");
        $ins->execute([$code, (int)$equityTypeId]);
        echo "  + created Retained Earnings equity account (#" . $pdo->lastInsertId() . ", code {$code}).\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
