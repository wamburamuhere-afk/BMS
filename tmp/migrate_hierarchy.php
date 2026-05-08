<?php
require_once __DIR__ . '/../roots.php';
global $pdo;
try {
    $pdo->exec("ALTER TABLE project_planning_tasks ADD COLUMN parent_id INT NULL AFTER report_id");
    $pdo->exec("ALTER TABLE project_planning_tasks ADD COLUMN level INT DEFAULT 0 AFTER parent_id");
    $pdo->exec("ALTER TABLE project_planning_tasks ADD COLUMN temp_id_mapped VARCHAR(50) NULL AFTER level");
    echo "Columns added successfully";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
