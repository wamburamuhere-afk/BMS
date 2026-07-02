<?php
require_once 'roots.php';
echo "Current Month: " . date('Y-m') . "\n";
echo "Current Year: " . date('Y') . "\n\n";
$stmt = $pdo->query("SELECT expense_id, expense_date, amount FROM expenses ORDER BY expense_date DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
