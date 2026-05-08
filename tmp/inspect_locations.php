<?php
require 'includes/config.php';
$stmt = $pdo->query('SELECT COUNT(*) FROM locations');
echo "LOCATIONS COUNT: " . $stmt->fetchColumn() . PHP_EOL;

$stmt = $pdo->query('SELECT * FROM locations LIMIT 5');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
