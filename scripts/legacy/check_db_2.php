<?php
require_once __DIR__ . '/roots.php';
global $pdo;

echo "Selected Accounts:\n";
$stmt = $pdo->query("SELECT * FROM accounts WHERE account_id IN (4, 6)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nAll Expense Categories:\n";
$stmt = $pdo->query("SELECT category_id, category_name FROM expense_categories");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
