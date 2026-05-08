<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$stmt = $pdo->query("SHOW COLUMNS FROM projects LIKE 'status'");
$row = $stmt->fetch();
echo $row['Type'];
?>
