<?php
require_once 'roots.php';
global $pdo;

$stmt = $pdo->prepare("SELECT * FROM purchase_returns WHERE purchase_return_id = 9");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($row);

if ($row['receipt_id']) {
    echo "\nGRN Details (receipt_id: {$row['receipt_id']}):\n";
    $stmt = $pdo->prepare("SELECT * FROM purchase_receipts WHERE receipt_id = ?");
    $stmt->execute([$row['receipt_id']]);
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
}
