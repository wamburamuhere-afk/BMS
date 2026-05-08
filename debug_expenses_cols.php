<?php
require_once 'roots.php';
require_once 'includes/config.php';
$cols = $pdo->query("DESCRIBE expenses")->fetchAll(PDO::FETCH_COLUMN);
echo "Expenses columns: " . implode(', ', $cols) . "\n";
