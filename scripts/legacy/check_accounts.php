<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT account_name, account_type, account_code FROM accounts WHERE account_type IN ('income', 'expense')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['account_code']} | {$row['account_name']} | {$row['account_type']}\n";
}
