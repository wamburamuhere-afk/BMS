<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$s = $pdo->prepare('SELECT p.page_key, rp.* FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.permission_id WHERE rp.role_id = ?');
$s->execute([2]);
while($r = $s->fetch(PDO::FETCH_ASSOC)) print_r($r);
