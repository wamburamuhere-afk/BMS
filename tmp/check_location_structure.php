<?php
require 'includes/config.php';
echo "DISTRICTS STRUCTURE:" . PHP_EOL;
$stmt = $pdo->query('DESCRIBE districts');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
echo "COUNCILS STRUCTURE:" . PHP_EOL;
$stmt = $pdo->query('DESCRIBE councils');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
echo "WARDS STRUCTURE:" . PHP_EOL;
$stmt = $pdo->query('DESCRIBE wards');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
