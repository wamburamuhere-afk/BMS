<?php
// api/pos/toggle_employment_type_status.php — activate/deactivate an employment type.
// No hard delete: the enum is active/inactive only (matches the existing convention
// for this table — every read site in the app already filters WHERE status='active').
// Deactivating is blocked while employees still reference it, so the wizard's
// dropdown (WHERE status='active') can never silently drop an in-use employee's type.
// scope-audit: skip — the "still in use" count below is a company-wide business-
// integrity guard, not a data listing: it must see ALL active employees regardless
// of the caller's project scope, otherwise a manager could deactivate an employment
// type that is still used by employees simply outside their own visibility.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canDelete('employment_types')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id     = (int)($_POST['type_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0) throw new Exception('Missing employment type.');
    if (!in_array($status, ['active', 'inactive'], true)) throw new Exception('Invalid status.');

    if ($status === 'inactive') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employment_type_id = ? AND status = 'active'");
        $chk->execute([$id]);
        $inUse = (int)$chk->fetchColumn();
        if ($inUse > 0) {
            throw new Exception("Cannot deactivate: $inUse active employee(s) still use this employment type. Reassign them first.");
        }
    }

    $pdo->prepare("UPDATE employment_types SET status = ?, updated_at = NOW() WHERE type_id = ?")->execute([$status, $id]);
    logActivity($pdo, $_SESSION['user_id'], "Employment type $status", "employment type id $id set to $status");
    echo json_encode(['success' => true, 'message' => 'Status updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
