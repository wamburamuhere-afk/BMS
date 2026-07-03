<?php
// API: Manage employee document types (Tier 2 — add / rename / deactivate).
// Gate: canEdit('employee_documents'). Types are lookup rows; delete is a
// deactivate (existing documents keep their type).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// list action is a read — view is enough; writes need edit
$action = trim($_POST['action'] ?? $_GET['action'] ?? 'list');

if ($action === 'list') {
    if (!canView('employee_documents')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    $rows = $pdo->query("SELECT doc_type_id, type_name, requires_expiry, sort_order, status
                         FROM employee_document_types WHERE status != 'deleted'
                         ORDER BY sort_order, type_name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if (!canEdit('employee_documents')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage document types']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

try {
    switch ($action) {
        case 'add': {
            $name = trim($_POST['type_name'] ?? '');
            $requires_expiry = intval($_POST['requires_expiry'] ?? 0) ? 1 : 0;
            if ($name === '') throw new Exception('Type name is required');

            $chk = $pdo->prepare("SELECT doc_type_id, status FROM employee_document_types WHERE type_name = ?");
            $chk->execute([$name]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['status'] !== 'deleted') throw new Exception('That type already exists');

            if ($existing) {   // resurrect a soft-deleted name
                $pdo->prepare("UPDATE employee_document_types SET status = 'active', requires_expiry = ? WHERE doc_type_id = ?")
                    ->execute([$requires_expiry, (int)$existing['doc_type_id']]);
                $id = (int)$existing['doc_type_id'];
            } else {
                $pdo->prepare("INSERT INTO employee_document_types (type_name, requires_expiry, created_by) VALUES (?, ?, ?)")
                    ->execute([$name, $requires_expiry, $_SESSION['user_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            logActivity($pdo, $_SESSION['user_id'], 'Add document type', "added employee document type '$name'");
            echo json_encode(['success' => true, 'message' => 'Type added', 'doc_type_id' => $id]);
            break;
        }

        case 'rename': {
            $id = intval($_POST['doc_type_id'] ?? 0);
            $name = trim($_POST['type_name'] ?? '');
            if (!$id || $name === '') throw new Exception('Type id and new name are required');
            $pdo->prepare("UPDATE employee_document_types SET type_name = ? WHERE doc_type_id = ? AND status != 'deleted'")
                ->execute([$name, $id]);
            logActivity($pdo, $_SESSION['user_id'], 'Rename document type', "renamed employee document type #$id to '$name'");
            echo json_encode(['success' => true, 'message' => 'Type renamed']);
            break;
        }

        case 'deactivate': {
            $id = intval($_POST['doc_type_id'] ?? 0);
            if (!$id) throw new Exception('Type id is required');
            $pdo->prepare("UPDATE employee_document_types SET status = 'inactive' WHERE doc_type_id = ?")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], 'Deactivate document type', "deactivated employee document type #$id");
            echo json_encode(['success' => true, 'message' => 'Type deactivated']);
            break;
        }

        case 'activate': {
            $id = intval($_POST['doc_type_id'] ?? 0);
            if (!$id) throw new Exception('Type id is required');
            $pdo->prepare("UPDATE employee_document_types SET status = 'active' WHERE doc_type_id = ? AND status != 'deleted'")->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], 'Activate document type', "activated employee document type #$id");
            echo json_encode(['success' => true, 'message' => 'Type activated']);
            break;
        }

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if ($e instanceof PDOException && strpos($e->getMessage(), 'Duplicate') !== false) {
        echo json_encode(['success' => false, 'message' => 'That type name already exists']);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
