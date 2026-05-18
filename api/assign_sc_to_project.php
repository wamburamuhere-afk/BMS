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

$action      = trim($_POST['action']       ?? 'assign');
$supplier_id = intval($_POST['supplier_id'] ?? 0);
$project_id  = intval($_POST['project_id']  ?? 0);
$entity_type = trim($_POST['entity_type']   ?? 'sub_contractor'); // 'sub_contractor' | 'supplier'

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'ID and project ID are required']);
    exit();
}

try {
    if ($entity_type === 'supplier') {
        $chk = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
        $chk->execute([$supplier_id]);
        if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Supplier not found']); exit(); }
        $tbl          = 'supplier_projects';
        $msg_assigned = 'Supplier assigned to project successfully';
        $msg_removed  = 'Supplier removed from project';
    } else {
        $chk = $pdo->prepare("SELECT supplier_id FROM sub_contractors WHERE supplier_id = ? AND status != 'deleted'");
        $chk->execute([$supplier_id]);
        if (!$chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Sub-contractor not found']); exit(); }
        $tbl          = 'sub_contractor_projects';
        $msg_assigned = 'Sub-contractor assigned to project successfully';
        $msg_removed  = 'Sub-contractor removed from project';
    }

    $pr = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
    $pr->execute([$project_id]);
    if (!$pr->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Project not found']);
        exit();
    }

    if ($action === 'unassign') {
        $pdo->prepare("DELETE FROM $tbl WHERE supplier_id = ? AND project_id = ?")
            ->execute([$supplier_id, $project_id]);
        logActivity($pdo, $_SESSION['user_id'], "$msg_removed (supplier #$supplier_id, project #$project_id)");
        echo json_encode(['success' => true, 'message' => $msg_removed]);
    } else {
        $pdo->prepare("INSERT IGNORE INTO $tbl (supplier_id, project_id, assigned_by) VALUES (?, ?, ?)")
            ->execute([$supplier_id, $project_id, $_SESSION['user_id']]);
        logActivity($pdo, $_SESSION['user_id'], "$msg_assigned (supplier #$supplier_id, project #$project_id)");
        echo json_encode(['success' => true, 'message' => $msg_assigned]);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
