<?php
require_once __DIR__ . '/../roots.php';
global $pdo;
try {
    $pdo->exec("ALTER TABLE brands ADD COLUMN website VARCHAR(255) DEFAULT NULL AFTER brand_name");
    echo "Column 'website' added successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
