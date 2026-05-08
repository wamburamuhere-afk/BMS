<?php
require_once 'roots.php';
global $pdo;

$stmt = $pdo->query("SHOW CREATE TABLE accounts");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'];
?>
