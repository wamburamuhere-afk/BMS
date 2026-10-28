<?php
/**
 * migrations/2026_10_28_expenses_payee_columns_legacy_db.php (legacy-DB mirror of the tenant migration)
 *
 * The root install (BJP's books on bms / demo) runs on the legacy DB and only
 * gets migrations/*.php, so migrations/tenant/2026_10_28_expenses_payee_columns.php
 * never reaches it — yet demo.bjptechnologies.co.tz is where the Expenses list
 * failed ("DataTables warning: table id=expensesTable - Ajax error") because
 * expenses.paid_to_type / paid_to_id / payee_manual_role / payee_manual_name
 * were missing. Same repair here: adds whichever of the four are missing (no
 * AFTER clause) and widens an old paid_to_type that lacks 'sub_contractor' /
 * 'other'. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: expenses payee columns...\n";
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
