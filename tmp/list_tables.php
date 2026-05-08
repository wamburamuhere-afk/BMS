<?php
include 'c:/wamp64/www/bms/roots.php';
global $pdo;
$stmt = $pdo->query('SHOW TABLES');
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN), JSON_PRETTY_PRINT);
