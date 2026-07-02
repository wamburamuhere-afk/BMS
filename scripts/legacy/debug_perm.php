<?php
require 'roots.php';
$stmt = $pdo->prepare('SELECT * FROM permissions WHERE page_key = ?');
$stmt->execute(['assets']);
print_r($stmt->fetch());
