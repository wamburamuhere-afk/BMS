<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$s = $pdo->query('SELECT * FROM roles');
while($r = $s->fetch(PDO::FETCH_ASSOC)) print_r($r);
