<?php
require 'includes/config.php';
echo "COUNCILS for District 100:" . PHP_EOL;
$stmt = $pdo->query("SELECT council_id, council_name FROM councils WHERE district_id = 100");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['council_id']}, Name: {$row['council_name']}" . PHP_EOL;
}
echo "WARDS for Council 1:" . PHP_EOL;
$stmt = $pdo->query("SELECT ward_id, ward_name FROM wards WHERE council_id = 1");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['ward_id']}, Name: {$row['ward_name']}" . PHP_EOL;
}
