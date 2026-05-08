<?php
require_once 'c:/wamp64/www/bms/includes/config.php';
$stmt = $pdo->query("DESCRIBE tenders");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
