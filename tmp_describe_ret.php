<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$t1 = $pdo->query("DESCRIBE purchase_returns")->fetchAll(PDO::FETCH_ASSOC);
$t2 = $pdo->query("DESCRIBE purchase_return_items")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(["purchase_returns" => $t1, "purchase_return_items" => $t2], JSON_PRETTY_PRINT);
