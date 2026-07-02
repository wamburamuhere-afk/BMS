<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SHOW CREATE TABLE product_stocks");
echo $stmt->fetchColumn(1);
