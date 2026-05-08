<?php
require_once __DIR__ . '/roots.php';
global $pdo;

echo "All Accounts with Category IDs:\n";
$stmt = $pdo->query("SELECT account_id, account_name, category_id, account_type FROM accounts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
