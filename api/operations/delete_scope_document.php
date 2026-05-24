<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    if (!canDelete('projects')) {
        throw new Exception('Access Denied: you do not have permission to delete scope documents');
    }

    $project_id = $_POST['project_id'] ?? null;
    $scope_type = $_POST['scope_type'] ?? 'original';
    $addendum_no = $_POST['addendum_no'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');

    $sql = "SELECT id, file_path FROM project_scope_documents WHERE project_id = ? AND scope_type = ?";
    $params = [$project_id, $scope_type];
    if ($scope_type === 'variation') {
        $sql .= " AND addendum_no = ?";
        $params[] = $addendum_no;
    } else {
        $sql .= " AND addendum_no IS NULL";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Physical delete
        $file = __DIR__ . '/../../' . $existing['file_path'];
        if (file_exists($file)) @unlink($file);

        // DB delete
        $stmtDelete = $pdo->prepare("DELETE FROM project_scope_documents WHERE id = ?");
        $stmtDelete->execute([$existing['id']]);

        // Phase 3c — scope documents are contractual evidence.
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Scope Document", "Document ID: {$existing['id']}, file: " . ($existing['file_path'] ?? ''));

        echo json_encode(['success' => true, 'message' => 'Document link removed successfully']);
    } else {
        echo json_encode(['success' => true, 'message' => 'No document found to delete']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
