<?php
require_once 'roots.php';
try {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN project_id INT NULL AFTER category_id");
    echo "Column project_id added to suppliers table successfully.";
} catch (PDOException $e) {
    echo "Error adding column: " . $e->getMessage();
}
