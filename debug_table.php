<?php
require_once __DIR__ . '/roots.php';
global $pdo;

$stmt = $pdo->query("SHOW CREATE TABLE documents");
$res = $stmt->fetch(PDO::FETCH_ASSOC);
echo $res['Create Table'];
