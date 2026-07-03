<?php
// API: Get checklist templates (with items), or active employee checklists,
// or one checklist's items (Tier 4, Phase 4.4).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('hr_checklists')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$mode = trim($_GET['mode'] ?? 'templates');

try {
    if ($mode === 'templates') {
        $tpls = $pdo->query("SELECT * FROM checklist_templates WHERE status != 'deleted' ORDER BY template_type, template_name")->fetchAll(PDO::FETCH_ASSOC);
        $items = $pdo->query("SELECT item_id, template_id, item_text, sort_order FROM checklist_template_items ORDER BY sort_order, item_id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'templates' => $tpls, 'items' => $items]);
        exit;
    }

    if ($mode === 'checklist') {
        $cid = intval($_GET['checklist_id'] ?? 0);
        if (!$cid) { echo json_encode(['success' => false, 'message' => 'Checklist id is required']); exit; }
        if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_checklists', 'checklist_id', $cid);
        $c = $pdo->prepare("SELECT ec.*, e.first_name, e.last_name FROM employee_checklists ec JOIN employees e ON e.employee_id=ec.employee_id WHERE ec.checklist_id=? AND ec.status!='deleted'");
        $c->execute([$cid]);
        $checklist = $c->fetch(PDO::FETCH_ASSOC);
        if (!$checklist) { echo json_encode(['success' => false, 'message' => 'Checklist not found']); exit; }
        $it = $pdo->prepare("SELECT eci.*, u.username AS done_by_name FROM employee_checklist_items eci LEFT JOIN users u ON u.user_id=eci.done_by WHERE eci.checklist_id=? ORDER BY eci.sort_order, eci.item_id");
        $it->execute([$cid]);
        echo json_encode(['success' => true, 'data' => $checklist, 'items' => $it->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // mode = active — employee checklists list (scope-filtered), optionally one employee
    $employee_id = intval($_GET['employee_id'] ?? 0);
    $status = trim($_GET['status'] ?? '');
    $where = ["ec.status != 'deleted'"]; $params = [];
    if ($status !== '') { $where[] = "ec.status = ?"; $params[] = $status; }
    if ($employee_id) {
        $where[] = "ec.employee_id = ?"; $params[] = $employee_id;
        if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);
    } else {
        $where[] = "1=1" . scopeFilterSqlNullable('project', 'e');
    }
    $sql = "
        SELECT ec.checklist_id, ec.employee_id, ec.checklist_type, ec.status, ec.created_at,
               e.first_name, e.last_name,
               (SELECT COUNT(*) FROM employee_checklist_items i WHERE i.checklist_id=ec.checklist_id) AS total,
               (SELECT COUNT(*) FROM employee_checklist_items i WHERE i.checklist_id=ec.checklist_id AND i.is_done=1) AS done
        FROM employee_checklists ec
        JOIN employees e ON e.employee_id = ec.employee_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ec.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_checklists error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
