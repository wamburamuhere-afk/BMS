<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE cash_register_shifts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
