<?php
// File: api/sales/delete_credit_note.php
// Soft-deletes a credit note (status='deleted'). Paid notes cannot be deleted
// (their cash settlement is posted to the ledger); reverse the payment first.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('credit_notes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

global $pdo;
$id = intval($_POST['credit_note_id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid credit note ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT credit_note_number, status FROM credit_notes WHERE credit_note_id = ?");
    $stmt->execute([$id]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cn || $cn['status'] === 'deleted') {
        echo json_encode(['success' => false, 'message' => 'Credit note not found']);
        exit;
    }
    if ($cn['status'] === 'paid') {
        echo json_encode(['success' => false, 'message' => 'A paid credit note cannot be deleted. Reverse the payment first.']);
        exit;
    }

    $pdo->prepare("UPDATE credit_notes SET status = 'deleted', updated_at = NOW() WHERE credit_note_id = ?")
        ->execute([$id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Delete Credit Note',
        "$user_name deleted Credit Note #{$cn['credit_note_number']}");

    echo json_encode(['success' => true, 'message' => 'Credit note deleted successfully.']);
} catch (PDOException $e) {
    error_log('delete_credit_note error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
