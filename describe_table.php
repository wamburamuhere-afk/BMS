<?php
require 'roots.php';
global $pdo;
$table = $argv[1] ?? 'departments';
$stmt = $pdo->query("DESCRIBE $table");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
