<?php
include 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT id, project_id, scope_type, description FROM project_milestones ORDER BY id DESC LIMIT 10");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($results);
