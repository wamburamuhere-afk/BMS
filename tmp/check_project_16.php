<?php
include 'roots.php';
global $pdo;
$project_id = 16;
$stmt = $pdo->prepare("SELECT * FROM project_milestones WHERE project_id = ?");
$stmt->execute([$project_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Data for Project $project_id:\n";
print_r($results);
