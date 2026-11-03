<?php
/**
 * migrations/tenant/2026_10_28_expenses_payee_columns.php
 *
 * The Expenses list (api/account/get_expenses.php) and every expense
 * create/edit/detail endpoint read expenses.paid_to_type / paid_to_id /
 * payee_manual_role / payee_manual_name. paid_to_type + paid_to_id were only
 * ever added by a hand-run one-off (scripts/legacy/migrate_expenses_paid_to.php)
 * — no migration created them — so a database that never had that script run
 * lacks them. There the list query fails with "Unknown column 'e.paid_to_type'",
 * the endpoint returns 500, and the page shows "DataTables warning: table
 * id=expensesTable - Ajax error" (seen on demo.bjptechnologies.co.tz).
 * 2026_09_15_expenses_simple_pos.php could not repair that: it skips a missing
 * paid_to_type and adds payee_manual_role "AFTER paid_to_id", which errors.
 *
 * Adds whichever of the four columns are missing (no AFTER clause, so it
 * never depends on another optional column) and widens an existing
 * paid_to_type that still lacks 'sub_contractor' / 'other'. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: expenses payee columns...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'expenses'")->fetch()) {
        echo "  ~ expenses table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    $payeeEnum = "ENUM('supplier','staff','sub_contractor','other') NULL DEFAULT NULL";
    $cols = [
        'paid_to_type'      => "ADD COLUMN `paid_to_type` $payeeEnum",
        'paid_to_id'        => "ADD COLUMN `paid_to_id` INT NULL DEFAULT NULL",
        'payee_manual_role' => "ADD COLUMN `payee_manual_role` VARCHAR(100) NULL DEFAULT NULL",
        'payee_manual_name' => "ADD COLUMN `payee_manual_name` VARCHAR(150) NULL DEFAULT NULL",
    ];
    foreach ($cols as $name => $ddl) {
        if ($pdo->query("SHOW COLUMNS FROM expenses LIKE " . $pdo->quote($name))->fetch()) {
            echo "  ~ expenses.$name already exists — skipping.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE expenses $ddl");
        echo "  + expenses.$name column added.\n";
    }

    // An older paid_to_type (ENUM('supplier','staff') from the legacy script)
    // rejects sub-contractor and manual payees — widen it.
    $col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'paid_to_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && (stripos($col['Type'], "'sub_contractor'") === false || stripos($col['Type'], "'other'") === false)) {
        $pdo->exec("ALTER TABLE expenses MODIFY COLUMN `paid_to_type` $payeeEnum");
        echo "  + expenses.paid_to_type now allows 'sub_contractor' and 'other'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
