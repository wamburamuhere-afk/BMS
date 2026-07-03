<?php
// API: Soft-delete an employee document (Tier 2).
// Also clears the linked library row's expire_date so stale expiry alerts stop.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('employee_documents')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete employee documents']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$emp_doc_id = intval($_POST['emp_doc_id'] ?? $_POST['id'] ?? 0);
if (!$emp_doc_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID is required']);
    exit;
}

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_documents', 'emp_doc_id', $emp_doc_id);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM employee_documents WHERE emp_doc_id = ? AND status != 'deleted' FOR UPDATE");
    $stmt->execute([$emp_doc_id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new Exception('Document not found');

    $pdo->prepare("UPDATE employee_documents SET status = 'deleted', updated_by = ? WHERE emp_doc_id = ?")
        ->execute([$_SESSION['user_id'], $emp_doc_id]);

    // Stop future expiry alerts for the removed document
    if (!empty($doc['library_document_id'])) {
        $pdo->prepare("UPDATE documents SET expire_date = NULL WHERE id = ?")
            ->execute([(int)$doc['library_document_id']]);
    }

    logActivity($pdo, $_SESSION['user_id'], 'Delete employee document',
        "deleted employee document '{$doc['document_name']}' (#$emp_doc_id)");
    logAudit($pdo, $_SESSION['user_id'], 'delete', [
        'activity_type' => 'delete',
        'entity_type'   => 'employee_document',
        'entity_id'     => $emp_doc_id,
        'description'   => "Deleted employee document '{$doc['document_name']}'",
        'old_values'    => ['status' => $doc['status'], 'expire_date' => $doc['expire_date']],
        'new_values'    => ['status' => 'deleted'],
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Document deleted']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
