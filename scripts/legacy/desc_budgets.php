<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE budgets");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
