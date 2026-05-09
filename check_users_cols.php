<?php
require_once 'roots.php';
global $pdo;
echo "COLUMNS IN users:\n";
$stmt = $pdo->query("DESCRIBE users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
