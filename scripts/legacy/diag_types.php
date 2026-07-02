<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$res = $pdo->query('SELECT DISTINCT account_type FROM accounts')->fetchAll(PDO::FETCH_COLUMN);
print_r($res);
