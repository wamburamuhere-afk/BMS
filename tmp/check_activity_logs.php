<?php
include 'c:/wamp64/www/bms/roots.php';
global $pdo;
$stmt = $pdo->query('DESCRIBE activity_logs');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
