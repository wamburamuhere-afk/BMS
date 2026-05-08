<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE projects");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
