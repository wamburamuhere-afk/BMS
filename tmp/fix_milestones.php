<?php
include 'roots.php';
global $pdo;
try {
    $count = $pdo->exec("UPDATE project_milestones SET scope_type = 'milestone' WHERE scope_type = 'original' OR scope_type IS NULL");
    echo "Updated $count existing records to 'milestone'\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
