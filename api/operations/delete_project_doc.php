<?php
// api/operations/delete_project_doc.php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete project documents']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    global $pdo;

    $id     = intval($_POST['id'] ?? 0);
    $origin = trim($_POST['origin'] ?? '');

    if ($id <= 0 || empty($origin)) {
        throw new Exception('Invalid request.');
    }

    // Phase E — project-scope gate for contract origin
    if ($origin === 'contract' && function_exists('userCan')) {
        $proj = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
        $proj->execute([$id]);
        $doc_project_id = $proj->fetchColumn();
        if ($doc_project_id && !userCan('project', (int)$doc_project_id)) {
            http_response_code(403);
            throw new Exception('Access denied: project not in your scope.');
        }
    }

    $file_path = null;

    switch ($origin) {
        case 'contract':
            $stmt = $pdo->prepare("SELECT contract_attachment FROM projects WHERE project_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Project not found.');
            $file_path = $row['contract_attachment'];
            $pdo->prepare("UPDATE projects SET contract_attachment = NULL WHERE project_id = ?")->execute([$id]);
            break;

        case 'budget':
            $stmt = $pdo->prepare("SELECT attachment FROM budgets WHERE budget_id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Budget not found.');
            $file_path = $row['attachment'];
            $pdo->prepare("UPDATE budgets SET attachment = NULL WHERE budget_id = ?")->execute([$id]);
            break;

        case 'voucher':
            $stmt = $pdo->prepare("SELECT attachment FROM payment_vouchers WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Voucher not found.');
            $file_path = $row['attachment'];
            $pdo->prepare("UPDATE payment_vouchers SET attachment = NULL WHERE id = ?")->execute([$id]);
            break;

        case 'manual':
            $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Document not found.');
            $file_path = $row['file_path'];
            $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
            break;

        default:
            throw new Exception('Unknown document origin.');
    }

    // Delete physical file
    if (!empty($file_path) && file_exists($file_path)) {
        @unlink($file_path);
    }

    logActivity($pdo, $_SESSION['user_id'], "Delete project document", "deleted project document [{$origin}] with id $id");

    echo json_encode(['success' => true, 'message' => 'Document deleted successfully.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
