<?php
require_once 'roots.php';
global $pdo;

echo "--- All Accounts (Detailed) ---\n";
$stmt = $pdo->query("SELECT * FROM accounts");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($results as $row) {
    echo "ID: {$row['account_id']} | Code: {$row['account_code']} | Name: {$row['account_name']} | TypeID: {$row['account_type_id']} | Type: {$row['account_type']} | Status: {$row['status']} | Created: {$row['created_at']}\n";
}
?>
