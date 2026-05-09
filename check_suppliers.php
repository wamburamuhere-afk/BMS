<?php
require_once 'roots.php';
global $pdo;
echo "SUPPLIERS:\n";
$stmt = $pdo->query("SELECT supplier_id, project_id, status FROM suppliers LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
