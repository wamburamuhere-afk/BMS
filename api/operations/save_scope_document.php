<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    $project_id = $_POST['project_id'] ?? null;
    $scope_type = $_POST['scope_type'] ?? 'original';
    $addendum_no = $_POST['addendum_no'] ?? null;
    
    if (!$project_id) throw new Exception('Project ID is required');

    if (!isset($_FILES['scope_file']) || $_FILES['scope_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES['scope_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_name = "scope_" . $project_id . "_" . $scope_type . ($addendum_no ? "_" . $addendum_no : "") . "_" . time() . "." . $ext;
    
    $upload_dir = __DIR__ . '/../../uploads/projects/scopes/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $file_path = 'uploads/projects/scopes/' . $new_name;
    $dest = $upload_dir . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception('Failed to move uploaded file');
    }
    // Determine a clear title for the library
    $title_map = [
        'original' => 'Original Scope Document',
        'revised' => 'Revised Scope Document',
        'variation' => 'Variation Scope (Addendum #' . ($addendum_no ?? 'N/A') . ')',
        'additional' => 'Additional Scope Document'
    ];
    $doc_title = ($title_map[$scope_type] ?? 'Project Scope Document') . ' - Project #' . $project_id;

    // Register in Library as PUBLIC so it shows up in global library as requested
    registerFileInLibrary($pdo, $file_path, $file['name'], $file['size'], $doc_title, 'project,scope,signed,' . $scope_type, $_SESSION['user_id'] ?? 0, (int)$project_id, 'public');

    $pdo->beginTransaction();

    // Check if entry exists
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
        // Delete old physical file if exists
        $old_file = __DIR__ . '/../../' . $existing['file_path'];
        if (file_exists($old_file)) @unlink($old_file);

        $stmtUpdate = $pdo->prepare("UPDATE project_scope_documents SET file_name = ?, file_path = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmtUpdate->execute([$file['name'], $file_path, $existing['id']]);
    } else {
        $stmtInsert = $pdo->prepare("INSERT INTO project_scope_documents (project_id, scope_type, addendum_no, file_name, file_path) VALUES (?, ?, ?, ?, ?)");
        $stmtInsert->execute([$project_id, $scope_type, $addendum_no ?: null, $file['name'], $file_path]);
    }

    $pdo->commit();

    // Phase 3c — scope documents are contractual evidence.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Saved Scope Document", "Project ID: $project_id, scope: $scope_type, file: {$file['name']}");

    echo json_encode(['success' => true, 'message' => 'Signed document uploaded successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
