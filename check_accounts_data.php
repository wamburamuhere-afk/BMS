<?php
require_once 'roots.php';
global $pdo;

echo "--- Account Types in DB ---\n";
$stmt = $pdo->query("SELECT * FROM account_types");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- All Accounts in DB ---\n";
$stmt = $pdo->query("SELECT account_id, account_code, account_name, account_type_id, account_type, status FROM accounts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
