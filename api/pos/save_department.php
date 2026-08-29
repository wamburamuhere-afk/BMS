<?php
// api/pos/save_department.php — create/update a department (name/code/leadership/parent).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('departments')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage departments']); exit; }

/** Walk the parent chain from $startId; true if $targetId appears anywhere in it (cycle). */
function departmentWouldCycle(PDO $pdo, int $childId, int $proposedParentId): bool {
    $seen = [];
    $current = $proposedParentId;
    while ($current) {
        if ($current === $childId) return true;
        if (isset($seen[$current])) return true; // pre-existing cycle in the data — stop, don't loop forever
        $seen[$current] = true;
        $stmt = $pdo->prepare("SELECT parent_department_id FROM departments WHERE department_id = ?");
        $stmt->execute([$current]);
        $current = $stmt->fetchColumn();
        $current = $current !== false && $current !== null ? (int)$current : null;
    }
    return false;
}

try {
    $id      = (int)($_POST['department_id'] ?? 0);
    $name    = trim($_POST['department_name'] ?? '');
    $code    = trim($_POST['department_code'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $parentId  = trim((string)($_POST['parent_department_id'] ?? '')) !== '' ? (int)$_POST['parent_department_id'] : null;
    $managerId = trim((string)($_POST['manager_id'] ?? '')) !== '' ? (int)$_POST['manager_id'] : null;
    $asstId    = trim((string)($_POST['assistant_manager_id'] ?? '')) !== '' ? (int)$_POST['assistant_manager_id'] : null;

    if ($name === '') throw new Exception('Name is required.');

    $dupCheck = $pdo->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(?) AND department_id != ?");
    $dupCheck->execute([$name, $id]);
    if ($dupCheck->fetchColumn()) throw new Exception('A department with this name already exists.');

    if ($parentId !== null) {
        if ($id > 0 && $parentId === $id) throw new Exception('A department cannot be its own parent.');
        $parentCheck = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ? AND status = 'active'");
        $parentCheck->execute([$parentId]);
        if (!$parentCheck->fetchColumn()) throw new Exception('Selected parent department does not exist or is inactive.');
        if ($id > 0 && departmentWouldCycle($pdo, $id, $parentId)) {
            throw new Exception('That parent is a descendant of this department — it would create a cycle.');
        }
    }

    foreach (['manager_id' => $managerId, 'assistant_manager_id' => $asstId] as $label => $empId) {
        if ($empId === null) continue;
        $chk = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ? AND status = 'active'");
        $chk->execute([$empId]);
        if (!$chk->fetchColumn()) throw new Exception('Selected ' . ($label === 'manager_id' ? 'manager' : 'assistant manager') . ' does not exist or is not active.');
        // §23 — a non-admin cannot make someone outside their project scope a
        // department head/assistant (sends its own 403 JSON + exits on failure).
        assertScopeForEmployee($empId);
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE departments
                          SET department_name = ?, department_code = ?, description = ?,
                              parent_department_id = ?, manager_id = ?, assistant_manager_id = ?, updated_at = NOW()
                        WHERE department_id = ?")
            ->execute([$name, ($code !== '' ? $code : null), ($desc !== '' ? $desc : null), $parentId, $managerId, $asstId, $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated department", "$name (ID: $id)");
        echo json_encode(['success' => true, 'message' => 'Department updated.', 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO departments (department_name, department_code, description, parent_department_id, manager_id, assistant_manager_id, status, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())")
            ->execute([$name, ($code !== '' ? $code : null), ($desc !== '' ? $desc : null), $parentId, $managerId, $asstId, $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created department", "$name (ID: $newId)");
        echo json_encode(['success' => true, 'message' => 'Department created.', 'id' => $newId]);
    }

} catch (Exception $e) {
    error_log('save_department error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
