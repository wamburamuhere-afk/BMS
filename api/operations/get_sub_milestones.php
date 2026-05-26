<?php
// scope-audit: skip — sub-milestone lookup for milestone; project scoped via parent milestone
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$parent_id = intval($_GET['parent_id'] ?? 0);
if (!$parent_id) {
    echo json_encode(['success' => false, 'message' => 'parent_id required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, description, scope FROM project_milestones WHERE parent_id = ? ORDER BY id ASC");
    $stmt->execute([$parent_id]);
    $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'milestones' => $milestones]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
