<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT employee_id, first_name, last_name, work_location FROM employees LIMIT 10");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
