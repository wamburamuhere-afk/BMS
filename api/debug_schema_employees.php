<?php
require_once '../roots.php';
global $pdo;
$stmt = $pdo->query("SHOW CREATE TABLE employees");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($result);
?>
