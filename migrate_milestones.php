<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    // Add parent_id column if it doesn't exist
    $pdo->exec("ALTER TABLE project_milestones ADD COLUMN parent_id INT NULL AFTER project_id");
    echo "Successfully added parent_id to project_milestones\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column parent_id already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
