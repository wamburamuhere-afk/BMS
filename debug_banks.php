<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT * FROM banks LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
