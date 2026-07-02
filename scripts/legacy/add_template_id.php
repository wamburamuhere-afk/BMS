<?php
require_once 'roots.php';
global $pdo;
try {
    $pdo->exec("ALTER TABLE documents ADD COLUMN template_id INT NULL AFTER category_id");
    echo "Column added successfully\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
