<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT * FROM account_types");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
