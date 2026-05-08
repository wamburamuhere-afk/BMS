<?php
include 'c:/wamp64/www/bms/roots.php';
global $pdo;
echo "Table: activity_logs\n";
$stmt = $pdo->query('DESCRIBE activity_logs');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "\nTable: audit_logs\n";
$stmt = $pdo->query('DESCRIBE audit_logs');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
