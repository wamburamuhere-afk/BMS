<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$res = $pdo->query('SELECT * FROM accounts')->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
