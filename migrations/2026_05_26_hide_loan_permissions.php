<?php
/**
 * Hide loan permission keys from Configure Permissions UI
 *
 * Adds is_hidden TINYINT column to permissions table and marks
 * 'loans' and 'loan_documents' as hidden. The pages remain fully
 * gated (autoEnforcePermission still enforces them); they simply
 * no longer appear in the role-based access matrix in user_roles.php.
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: hide loan permission keys...\n";

try {
    // Add is_hidden column if it doesn't exist
    $col = $pdo->query("SHOW COLUMNS FROM permissions LIKE 'is_hidden'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE permissions ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0");
        echo "  Added is_hidden column to permissions.\n";
    } else {
        echo "  is_hidden column already present (no-op).\n";
    }

    // Mark loan keys as hidden
    $stmt = $pdo->prepare("UPDATE permissions SET is_hidden = 1 WHERE page_key = ?");

    $stmt->execute(['loans']);
    echo "  Marked 'loans' as hidden (" . $stmt->rowCount() . " row).\n";

    $stmt->execute(['loan_documents']);
    echo "  Marked 'loan_documents' as hidden (" . $stmt->rowCount() . " row).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
