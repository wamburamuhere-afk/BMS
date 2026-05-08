<?php
include 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT id, project_id, description, amount FROM project_milestones WHERE scope_type = 'milestone' AND amount > 0");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($results);
