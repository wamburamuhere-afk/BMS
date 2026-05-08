<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    die("Project ID required");
}

$stmt = $pdo->prepare("SELECT id, scope_type, addendum_no, description FROM project_milestones WHERE project_id = ?");
$stmt->execute([$project_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($items);
echo "</pre>";
