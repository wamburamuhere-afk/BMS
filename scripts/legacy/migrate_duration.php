<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN duration_days INT DEFAULT 0 AFTER deadline");
    echo "Success: Column duration_days added to projects table.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
