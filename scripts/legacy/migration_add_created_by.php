<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    // Add created_by column to project_progress_reports
    $pdo->exec("ALTER TABLE project_progress_reports ADD COLUMN created_by INT NULL");
    echo "Column created_by added successfully to project_progress_reports.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
