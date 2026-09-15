<?php
/**
 * migrations/tenant/2026_09_15_expenses_simple_pos.php
 *
 * Simple POS mode — Expenses CRUD simplification (expenses_simple_pos_plan.md).
 * Adds what the simplified Add/Edit Expense form needs once the GL-account,
 * "Paid From", and Types & Categories UI are hidden from a Simple POS tenant:
 * `expenses.paid_to_type` gains an 'other' value, plus two free-text columns,
 * for the "More" manual payee (e.g. "Bodaboda" + a typed name) used when a
 * tenant has no active Staff/Supplier to pick from.
 *
 * No new account is seeded — core/gl_accounts.php::miscExpenseAccountId()
 * (the account a hidden expense-account field resolves to) falls back to the
 * standard chart's existing 9-1000 "Sundry Expenses" account, so every tenant
 * that ran migrations/2026_06_13_official_chart_of_accounts.php already has it.
 *
 * Ledger posting itself (core/expense_posting.php, core/payment_source.php)
 * is untouched — this only gives the form a place to store a manually-typed
 * payee.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: Expenses Simple POS mode...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'expenses'")->fetch()) {
        echo "  ~ expenses table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    // 1) expenses.paid_to_type — widen to allow 'other' (manual payee)
    $col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'paid_to_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], "'other'") === false) {
        $pdo->exec("ALTER TABLE expenses MODIFY COLUMN paid_to_type
            ENUM('supplier','staff','sub_contractor','other') NULL DEFAULT NULL");
        echo "  + expenses.paid_to_type now allows 'other'.\n";
    } else {
        echo "  ~ expenses.paid_to_type already allows 'other' (or column missing) — skipping.\n";
    }

    // 2) Manual/free-text payee columns (used only when paid_to_type = 'other')
    $cols = [
        'payee_manual_role' => "ADD COLUMN `payee_manual_role` VARCHAR(100) NULL DEFAULT NULL AFTER `paid_to_id`",
        'payee_manual_name' => "ADD COLUMN `payee_manual_name` VARCHAR(150) NULL DEFAULT NULL AFTER `payee_manual_role`",
    ];
    foreach ($cols as $name => $ddl) {
        $exists = $pdo->query("SHOW COLUMNS FROM expenses LIKE " . $pdo->quote($name))->fetch();
        if ($exists) {
            echo "  ~ expenses.$name already exists — skipping.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE expenses $ddl");
        echo "  + expenses.$name column added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
