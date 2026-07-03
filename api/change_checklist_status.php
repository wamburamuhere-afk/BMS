<?php
// API: Complete / cancel an employee checklist (Tier 4, Phase 4.4).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('hr_checklists')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $cid = intval($_POST['checklist_id'] ?? 0);
    $new = trim($_POST['status'] ?? '');
    if (!$cid) throw new Exception('Checklist id is required');
    if (!in_array($new, ['completed', 'cancelled'], true)) throw new Exception('Invalid status');
    if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_checklists', 'checklist_id', $cid);

    $cur = $pdo->query("SELECT status FROM employee_checklists WHERE checklist_id=$cid AND status!='deleted'")->fetchColumn();
    if ($cur === false) throw new Exception('Checklist not found');
    if ($cur !== 'in_progress') throw new Exception("This checklist is already $cur");

    if ($new === 'completed') {
        $open = (int)$pdo->query("SELECT COUNT(*) FROM employee_checklist_items WHERE checklist_id=$cid AND is_done=0")->fetchColumn();
        if ($open > 0) throw new Exception("$open item(s) are still open — tick them first");
        $pdo->prepare("UPDATE employee_checklists SET status='completed', completed_at=NOW() WHERE checklist_id=?")->execute([$cid]);
    } else {
        $pdo->prepare("UPDATE employee_checklists SET status='cancelled' WHERE checklist_id=?")->execute([$cid]);
    }
    logActivity($pdo, $_SESSION['user_id'], 'Change checklist status', "checklist #$cid → $new");
    echo json_encode(['success' => true, 'message' => "Checklist marked $new"]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
