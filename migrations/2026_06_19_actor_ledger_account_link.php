<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

/**
 * Phase 1 — Actor-as-account foundation.
 *
 * Each actor (customer, supplier, sub-contractor, employee) becomes a real
 * sub-account under its existing control account (customers → Trade Debtors,
 * suppliers + sub-contractors → Trade Creditors, employees → Salaries Payable).
 * This migration only adds the LINK column on each register; the sub-accounts
 * themselves are auto-created (Phase 2) and backfilled for existing rows (Phase 3).
 *
 * `ledger_account_id` → accounts.account_id of that actor's own GL sub-account.
 * NULL until the account is created/backfilled. Indexed for the reverse lookup.
 *
 * Idempotent: each ALTER is guarded by a SHOW COLUMNS check, so re-running is safe.
 */

echo "Starting migration: actor ledger_account_id link (customers/suppliers/sub_contractors/employees)...\n";

try {
    // Relax strict date validation for this session so servers with legacy
    // 0000-00-00 date values (e.g. probation_end_date) don't reject ALTER TABLE.
    $pdo->exec("SET SESSION sql_mode = ''");

    $targets = ['customers', 'suppliers', 'sub_contractors', 'employees'];

    foreach ($targets as $table) {
        // Table guard — skip cleanly if a register is absent on this server.
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
        if (!$exists) {
            echo "  - table `$table` not present — skipping.\n";
            continue;
        }

        // Column guard — add only when missing (idempotent).
        $col = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'ledger_account_id'")->fetch();
        if ($col) {
            echo "  - `$table`.ledger_account_id already exists — skipping ADD.\n";
        } else {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN ledger_account_id INT NULL DEFAULT NULL");
            echo "  - added `$table`.ledger_account_id.\n";
        }

        // Index guard — add only when missing (speeds the account → actor lookup).
        $idx = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = 'idx_ledger_account_id'")->fetch();
        if ($idx) {
            echo "  - `$table` index idx_ledger_account_id already exists — skipping.\n";
        } else {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX idx_ledger_account_id (ledger_account_id)");
            echo "  - indexed `$table`.ledger_account_id.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
