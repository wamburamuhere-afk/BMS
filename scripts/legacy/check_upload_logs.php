<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT * FROM audit_logs WHERE action = 'upload_document' ORDER BY id DESC LIMIT 5");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($logs, JSON_PRETTY_PRINT);
?>
