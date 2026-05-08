<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id = $_POST['project_id'] ?? null;
    $addendum_no = $_POST['addendum_no'] !== '' ? $_POST['addendum_no'] : null;

    if (!$project_id) throw new Exception('Project ID is required');

    $pdo->beginTransaction();

    // Delete milestones
    if ($addendum_no === null) {
        $stmt = $pdo->prepare("DELETE FROM project_milestones WHERE project_id = ? AND scope_type = 'variation' AND (addendum_no IS NULL OR addendum_no = '')");
        $stmt->execute([$project_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM project_milestones WHERE project_id = ? AND scope_type = 'variation' AND addendum_no = ?");
        $stmt->execute([$project_id, $addendum_no]);
    }
    
    // Delete document link if any
    if ($addendum_no === null) {
        $stmtDoc = $pdo->prepare("DELETE FROM project_scope_documents WHERE project_id = ? AND scope_type = 'variation' AND (addendum_no IS NULL OR addendum_no = '')");
        $stmtDoc->execute([$project_id]);
    } else {
        $stmtDoc = $pdo->prepare("DELETE FROM project_scope_documents WHERE project_id = ? AND scope_type = 'variation' AND addendum_no = ?");
        $stmtDoc->execute([$project_id, $addendum_no]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Addendum deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
