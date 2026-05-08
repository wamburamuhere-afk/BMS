<?php
include 'c:/wamp64/www/bms/roots.php';
global $pdo;
try {
    $pdo->exec("ALTER TABLE project_milestones ADD COLUMN amount DECIMAL(15,2) DEFAULT 0 AFTER scope");
    echo "Added amount column to project_milestones\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE project_milestones ADD COLUMN scope_type VARCHAR(50) DEFAULT 'original' AFTER project_id");
    echo "Added scope_type column to project_milestones\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE project_milestones ADD COLUMN addendum_no VARCHAR(50) DEFAULT NULL AFTER scope_type");
    echo "Added addendum_no column to project_milestones\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
