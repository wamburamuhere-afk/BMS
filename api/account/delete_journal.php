<?php
/**
 * api/account/delete_journal.php
 * ------------------------------
 * Deletes a manual journal entry + its lines + its books_transactions mirror.
 *
 * A POSTED journal is immutable (it is in the reports): it cannot be deleted —
 * reverse or void it first. Only draft / void / reversed entries may be removed.
 * This honours the rule that anything affecting the reports is unwound, never
 * left dangling.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canDelete('journals')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$id = (int)($_POST['entry_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid entry ID']); exit; }

try {
    $e = $pdo->prepare("SELECT entry_id, reference_number, status, transaction_id FROM journal_entries WHERE entry_id = ?");
    $e->execute([$id]);
    $entry = $e->fetch(PDO::FETCH_ASSOC);
    if (!$entry) { echo json_encode(['success' => false, 'message' => 'Journal entry not found']); exit; }

    if ($entry['status'] === 'posted') {
        echo json_encode(['success' => false, 'message' => 'A posted journal cannot be deleted. Reverse or void it first.']);
        exit;
    }

    $pdo->beginTransaction();

    // Remove the books_transactions mirror (and its transactions header) if linked.
    if (!empty($entry['transaction_id'])) {
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([(int)$entry['transaction_id']]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([(int)$entry['transaction_id']]);
    }
    $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted journal entry #$id ({$entry['reference_number']}, status {$entry['status']})");
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Journal entry deleted.']);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('delete_journal error: ' . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not delete: ' . $ex->getMessage()]);
}
