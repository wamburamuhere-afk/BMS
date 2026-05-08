<?php
require 'includes/config.php';
$stmt = $pdo->query('SHOW CREATE TABLE districts');
echo $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'] . PHP_EOL;
$stmt = $pdo->query('SHOW CREATE TABLE councils');
echo $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'] . PHP_EOL;
$stmt = $pdo->query('SHOW CREATE TABLE wards');
echo $stmt->fetch(PDO::FETCH_ASSOC)['Create Table'] . PHP_EOL;
