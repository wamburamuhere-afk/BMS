<?php
require_once __DIR__ . '/roots.php';
global $pdo;

echo "--- RFQ TABLE ---\n";
$stmt = $pdo->query("DESCRIBE rfq");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']}\n";
}

echo "\n--- RFQ_ITEMS TABLE ---\n";
$stmt = $pdo->query("DESCRIBE rfq_items");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} - {$row['Type']} - {$row['Null']} - {$row['Key']}\n";
}
