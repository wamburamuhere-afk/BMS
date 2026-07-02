<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT permission_id, page_key, page_name FROM permissions WHERE page_key LIKE '%account%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
