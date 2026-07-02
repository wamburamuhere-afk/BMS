<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$stmt = $pdo->query("SHOW CREATE TABLE products");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'];
?>
