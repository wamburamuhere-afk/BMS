<?php
require_once 'roots.php';
global $pdo;
$res = $pdo->query("DESCRIBE product_stocks")->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
