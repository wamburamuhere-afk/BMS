<?php
/**
 * 2026_06_15_fix_level_after_cogs_reclassification.php
 * -----------------------------------------------------
 * Follow-up to 2026_06_15_cogs_supplier_credit_remediation.php.
 *
 * That migration cleared parent_account_id for re-classified 4-xxxx accounts
 * but omitted `level = 1`. Any account whose parent was cleared to NULL must
 * be top-level (level = 1). This migration corrects those accounts.
 *
 * Detection criteria (dataset-agnostic):
 *   account_types.category = 'asset'   (re-classified target category)
 *   AND parent_account_id IS NULL       (parent was cleared → must be level 1)
 *   AND level != 1                      (the gap to fix)
 *   AND account_code LIKE '4-%'        (structural invariant: only 4-xxxx accounts
 *                                        were re-classified by the prior migration)
 *
 * Idempotent: once level = 1, the WHERE clause no longer matches — re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: fix level=1 for top-level re-classified accounts...\n";

try {
    $stmt = $pdo->prepare("
        UPDATE accounts a
          JOIN account_types at ON at.type_id = a.account_type_id
           SET a.level = 1
         WHERE a.parent_account_id IS NULL
           AND a.level            != 1
           AND a.account_code     LIKE '4-%'
           AND at.category         = 'asset'
    ");
    $stmt->execute();
    $count = $stmt->rowCount();

    if ($count > 0) {
        echo "  + fixed level → 1 for {$count} account(s) with no parent but level != 1.\n";
    } else {
        echo "  ~ no accounts needed correction (already correct or none matched).\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
