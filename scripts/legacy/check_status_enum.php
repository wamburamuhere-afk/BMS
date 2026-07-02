<?php
require_once 'roots.php';
global $pdo;
$res = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
print_r($res);
