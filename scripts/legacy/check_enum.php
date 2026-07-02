<?php
require_once 'roots.php';
$stmt = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'account_type'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
