<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SHOW TABLES LIKE 'cash_register%'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
