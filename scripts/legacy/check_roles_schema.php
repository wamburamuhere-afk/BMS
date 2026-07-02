<?php
require_once 'includes/config.php';
$res = $pdo->query('SHOW CREATE TABLE roles')->fetch(PDO::FETCH_ASSOC);
echo array_values($res)[1];
