<?php
require 'roots.php';
global $pdo;

$res = $pdo->query("SHOW TABLES");
$tables = $res->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . implode(", ", $tables) . "\n";

$res = $pdo->query("DESCRIBE departments");
if ($res) { echo "Departments schema found.\n"; print_r($res->fetchAll(PDO::FETCH_ASSOC)); }
$res = $pdo->query("DESCRIBE designations");
if ($res) { echo "Designations schema found.\n"; print_r($res->fetchAll(PDO::FETCH_ASSOC)); }
$res = $pdo->query("DESCRIBE employment_types");
if ($res) { echo "Employment_types schema found.\n"; print_r($res->fetchAll(PDO::FETCH_ASSOC)); }
?>
