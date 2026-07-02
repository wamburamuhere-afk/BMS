<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE template_categories");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
