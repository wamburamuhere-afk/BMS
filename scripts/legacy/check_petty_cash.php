<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SHOW TABLES LIKE 'petty_cash%'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
