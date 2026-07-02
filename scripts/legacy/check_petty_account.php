<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT * FROM accounts WHERE account_name LIKE '%Petty Cash%' OR account_code LIKE '%PC%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
