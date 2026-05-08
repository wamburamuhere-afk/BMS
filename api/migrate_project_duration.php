<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $col = 'duration';
    $definition = "VARCHAR(255) DEFAULT NULL AFTER duration_days";
    
    $check = $pdo->query("SHOW COLUMNS FROM projects LIKE '$col'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN $col $definition");
        echo "Added column: $col to projects table\n";
    } else {
        echo "Column $col already exists in projects table.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
