<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
// Add 'draft' to the enum
$pdo->exec("ALTER TABLE projects MODIFY COLUMN status ENUM('draft','planning','active','on_hold','completed','cancelled') DEFAULT 'draft'");
echo "Success";
?>
