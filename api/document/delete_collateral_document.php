<?php
// ajax/delete_collateral_document.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('documents')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete collateral documents']);
    exit();
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

try {
    // Get file path first
    $stmt = $pdo->prepare("SELECT file_path FROM collateral_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if ($doc) {
        if (file_exists('../' . $doc['file_path'])) {
            unlink('../' . $doc['file_path']);
        }
        
        $stmt = $pdo->prepare("DELETE FROM collateral_attachments WHERE id = ?");
        $stmt->execute([$id]);

        logActivity($pdo, $_SESSION['user_id'], "Deleted Collateral Document", "Attachment ID: $id");

        echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
