<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action      = trim($_POST['action']      ?? 'assign');
$supplier_id = intval($_POST['supplier_id'] ?? 0);
$project_id  = intval($_POST['project_id']  ?? 0);

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'Sub-contractor ID and project ID are required']);
    exit();
}

try {
    // Verify both records exist
    $sc = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id = ? AND status != 'deleted'");
    $sc->execute([$supplier_id]);
    if (!$sc->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Sub-contractor not found']);
        exit();
    }

    $pr = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
    $pr->execute([$project_id]);
    if (!$pr->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }

    if ($action === 'unassign') {
        $pdo->prepare("DELETE FROM sub_contractor_projects WHERE supplier_id = ? AND project_id = ?")
            ->execute([$supplier_id, $project_id]);
        echo json_encode(['success' => true, 'message' => 'Sub-contractor removed from project']);
    } else {
        $pdo->prepare("INSERT IGNORE INTO sub_contractor_projects (supplier_id, project_id, assigned_by) VALUES (?, ?, ?)")
            ->execute([$supplier_id, $project_id, $_SESSION['user_id']]);
        echo json_encode(['success' => true, 'message' => 'Sub-contractor assigned to project successfully']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
