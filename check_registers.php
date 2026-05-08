<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SHOW TABLES LIKE 'cash_registers'");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
