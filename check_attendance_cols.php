<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE attendance");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
