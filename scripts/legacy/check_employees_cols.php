<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE employees");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_PRETTY_PRINT);
