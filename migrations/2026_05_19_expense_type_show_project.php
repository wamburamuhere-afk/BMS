<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add show_project to expense_types...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM expense_types LIKE 'show_project'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE expense_types ADD COLUMN show_project TINYINT(1) NOT NULL DEFAULT 1");
        echo "Column show_project added to expense_types.\n";
    } else {
        echo "Column show_project already exists — skipping ALTER.\n";
    }

    // Set flag to 0 for known non-project types (case-insensitive match)
    $affected = $pdo->exec(
        "UPDATE expense_types SET show_project = 0
         WHERE LOWER(TRIM(name)) IN ('administrative','fixed','operating')
           AND show_project = 1"
    );
    echo "Flagged $affected non-project type(s) with show_project = 0.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
