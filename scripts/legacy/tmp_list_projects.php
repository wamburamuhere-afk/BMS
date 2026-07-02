<?php
require 'roots.php';
$stmt = $pdo->query('SHOW TABLES LIKE "%projects%"');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
