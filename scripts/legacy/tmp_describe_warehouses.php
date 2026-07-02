<?php
require 'roots.php';
$stmt = $pdo->query('DESCRIBE warehouses');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
