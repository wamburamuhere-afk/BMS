<?php
require_once 'includes/config.php';
global $pdo;

echo "Loans Count: " . $pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn() . "\n";
echo "Products Count: " . $pdo->query("SELECT COUNT(*) FROM loan_products")->fetchColumn() . "\n";
echo "Customers Count: " . $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn() . "\n";

echo "\n--- First 5 Loans Sample ---\n";
$stmt = $pdo->query("SELECT loan_id, customer_id, product_id, amount, status FROM loans LIMIT 5");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

echo "\n--- Sample Customer IDs ---\n";
$stmt = $pdo->query("SELECT customer_id, customer_name FROM customers LIMIT 5");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

echo "\n--- Sample Product IDs ---\n";
$stmt = $pdo->query("SELECT product_id, product_name FROM loan_products LIMIT 5");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
