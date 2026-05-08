<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE project_progress_report_details");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
