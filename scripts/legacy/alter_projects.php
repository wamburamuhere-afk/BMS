<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE projects 
        ADD COLUMN client_name VARCHAR(255) AFTER project_name,
        ADD COLUMN discipline VARCHAR(100) AFTER client_name,
        ADD COLUMN discipline_other VARCHAR(255) AFTER discipline,
        ADD COLUMN role_position VARCHAR(100) AFTER discipline_other,
        ADD COLUMN role_position_other VARCHAR(255) AFTER role_position,
        ADD COLUMN contract_attachment VARCHAR(255) AFTER role_position_other");
    echo "Columns added successfully";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
