<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: ensure can_review column on role_permissions...\n";

try {
    // core/permissions.php and user_roles.php both reference rp.can_review, but
    // no earlier migration ever created it (only can_approve was added). Any
    // environment that did not get the column added by hand errors with
    // "Unknown column 'can_review'". Add it here, idempotently.
    $colExists = $pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE role_permissions ADD COLUMN can_review TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete");
        echo "Column can_review added to role_permissions.\n";
    } else {
        echo "Column can_review already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
