<?php
// API: Manually spawn a checklist for an employee from a template (Tier 4, Phase 4.4).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/checklists.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('hr_checklists')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $template_id = intval($_POST['template_id'] ?? 0);
    if (!$employee_id) throw new Exception('Employee is required');
    if (!$template_id) throw new Exception('Template is required');
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id=? AND (status IS NULL OR status!='deleted')");
    $emp->execute([$employee_id]);
    $er = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$er) throw new Exception('Employee not found');

    $pdo->beginTransaction();
    $cid = spawnChecklistFromTemplate($pdo, $employee_id, $template_id, (int)$_SESSION['user_id']);
    if (!$cid) throw new Exception('Template not found or inactive');
    logActivity($pdo, $_SESSION['user_id'], 'Spawn checklist', "checklist #$cid for \"" . trim($er['first_name'] . ' ' . $er['last_name']) . "\"");
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Checklist created', 'checklist_id' => $cid]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
