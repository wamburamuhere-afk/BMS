<?php
require_once 'roots.php';
global $pdo;
echo "--- PETTY CASH ---\n";
$stmt = $pdo->query("DESCRIBE petty_cash_transactions");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
