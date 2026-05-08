<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$s = $pdo->query('SELECT page_key FROM permissions');
while($r = $s->fetch()) echo $r[0] . PHP_EOL;
