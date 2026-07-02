<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SHOW CREATE TABLE purchase_returns");
echo $stmt->fetchColumn(1);
