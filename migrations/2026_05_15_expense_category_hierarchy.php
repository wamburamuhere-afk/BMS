<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Add parent_id to expense_categories for unlimited nesting...\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM expense_categories LIKE 'parent_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE expense_categories ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER type_id");
        echo "Added parent_id column.\n";
    } else {
        echo "parent_id column already exists.\n";
    }

    $fkExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'expense_categories'
           AND CONSTRAINT_NAME = 'fk_expense_cat_parent'"
    )->fetchColumn();

    if (!$fkExists) {
        $pdo->exec("ALTER TABLE expense_categories ADD CONSTRAINT fk_expense_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id) ON DELETE CASCADE");
        echo "Added self-referential FK with ON DELETE CASCADE.\n";
    } else {
        echo "FK fk_expense_cat_parent already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
