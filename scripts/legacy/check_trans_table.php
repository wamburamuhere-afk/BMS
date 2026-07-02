<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE transactions");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
