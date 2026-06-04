<?php
// File: api/purchase/delete_debit_note.php
// Soft-deletes a debit note (status='deleted'). Paid notes cannot be deleted
// (their cash settlement is posted to the ledger).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canDelete('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id = intval($_POST['debit_note_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid debit note ID']); exit; }

try {
    $stmt = $pdo->prepare("SELECT debit_note_number, status FROM debit_notes WHERE debit_note_id = ?");
    $stmt->execute([$id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn || $dn['status'] === 'deleted') { echo json_encode(['success' => false, 'message' => 'Debit note not found']); exit; }
    if ($dn['status'] === 'paid') { echo json_encode(['success' => false, 'message' => 'A settled debit note cannot be deleted. Reverse the payment first.']); exit; }

    $pdo->prepare("UPDATE debit_notes SET status = 'deleted', updated_at = NOW() WHERE debit_note_id = ?")->execute([$id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Delete Debit Note',
        "$user_name deleted Debit Note #{$dn['debit_note_number']}");

    echo json_encode(['success' => true, 'message' => 'Debit note deleted successfully.']);
} catch (PDOException $e) {
    error_log('delete_debit_note error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
