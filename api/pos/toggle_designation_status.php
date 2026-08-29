<?php
// api/pos/toggle_designation_status.php — activate/deactivate a designation.
// No hard delete: matches the existing active/inactive-only convention for this
// table. Deactivating is blocked while active employees still hold it.
// scope-audit: skip — the "still in use" count below is a company-wide business-
// integrity guard, not a data listing: it must see ALL active employees regardless
// of the caller's project scope, otherwise a manager could deactivate a designation
// that is still held by employees simply outside their own visibility.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canDelete('designations')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id     = (int)($_POST['designation_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0) throw new Exception('Missing designation.');
    if (!in_array($status, ['active', 'inactive'], true)) throw new Exception('Invalid status.');

    if ($status === 'inactive') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE designation_id = ? AND status = 'active'");
        $chk->execute([$id]);
        $inUse = (int)$chk->fetchColumn();
        if ($inUse > 0) {
            throw new Exception("Cannot deactivate: $inUse active employee(s) still hold this designation. Reassign them first.");
        }
    }

    $pdo->prepare("UPDATE designations SET status = ?, updated_at = NOW() WHERE designation_id = ?")->execute([$status, $id]);
    logActivity($pdo, $_SESSION['user_id'], "Designation $status", "designation id $id set to $status");
    echo json_encode(['success' => true, 'message' => 'Status updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
