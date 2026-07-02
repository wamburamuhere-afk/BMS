<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT permission_id, page_key, permission_name, module_name FROM permissions");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
?>
