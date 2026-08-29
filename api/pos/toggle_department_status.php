<?php
// api/pos/toggle_department_status.php — activate/deactivate a department.
// No hard delete: matches the existing active/inactive-only convention. Deactivating
// is blocked while active employees still belong to it, or while it still has
// active sub-departments under it (avoid a dangling active child under a hidden parent).
// scope-audit: skip — the "still in use" count below is a company-wide business-
// integrity guard, not a data listing: it must see ALL active employees regardless
// of the caller's project scope, otherwise a manager could deactivate a department
// that is still in active use by employees simply outside their own visibility.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canDelete('departments')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id     = (int)($_POST['department_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0) throw new Exception('Missing department.');
    if (!in_array($status, ['active', 'inactive'], true)) throw new Exception('Invalid status.');

    if ($status === 'inactive') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE department_id = ? AND status = 'active'");
        $chk->execute([$id]);
        $inUse = (int)$chk->fetchColumn();
        if ($inUse > 0) {
            throw new Exception("Cannot deactivate: $inUse active employee(s) still belong to this department. Reassign them first.");
        }

        $childChk = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE parent_department_id = ? AND status = 'active'");
        $childChk->execute([$id]);
        $activeChildren = (int)$childChk->fetchColumn();
        if ($activeChildren > 0) {
            throw new Exception("Cannot deactivate: $activeChildren active sub-department(s) still report to this department. Re-parent or deactivate them first.");
        }
    }

    $pdo->prepare("UPDATE departments SET status = ?, updated_at = NOW() WHERE department_id = ?")->execute([$status, $id]);
    logActivity($pdo, $_SESSION['user_id'], "Department $status", "department id $id set to $status");
    echo json_encode(['success' => true, 'message' => 'Status updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
