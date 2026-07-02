<?php
// api/pos/delete_salary_component.php — soft-delete a salary component (§12 soft delete).
// Existing employee assignments / posted payslips are unaffected (they keep their stored
// values); the component just stops appearing for new salary structures.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canDelete('payroll')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id = (int)($_POST['component_id'] ?? 0);
    if ($id <= 0) throw new Exception('Missing component.');

    // Component soft-delete + retirement of its employee assignments commit
    // together — no deleted component can keep live assignments.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE salary_components SET status = 'deleted', updated_at = NOW() WHERE component_id = ?")->execute([$id]);
        // Also retire any active employee assignments of this component.
        $pdo->prepare("UPDATE employee_salary_components SET status = 'inactive', updated_at = NOW() WHERE component_id = ? AND status = 'active'")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    logActivity($pdo, $_SESSION['user_id'], "Delete salary component", "deleted salary component with id $id");
    echo json_encode(['success' => true, 'message' => 'Component deleted.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
