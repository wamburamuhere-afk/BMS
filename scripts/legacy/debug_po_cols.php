<?php
require_once 'roots.php';
require_once 'includes/config.php';
$cols = $pdo->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
echo "Purchase Orders columns: " . implode(', ', $cols) . "\n";
