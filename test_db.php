<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT rp.*, p.page_key FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE p.page_key='chart_of_accounts'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
