<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE cash_register_transactions");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
