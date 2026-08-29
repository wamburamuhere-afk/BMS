<?php
// api/pos/save_designation.php — create/update a designation lookup.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('designations')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage designations']); exit; }

try {
    $id      = (int)($_POST['designation_id'] ?? 0);
    $name    = trim($_POST['designation_name'] ?? '');
    $deptId  = trim((string)($_POST['department_id'] ?? '')) !== '' ? (int)$_POST['department_id'] : null;
    $grade   = trim($_POST['pay_grade'] ?? '');
    $desc    = trim($_POST['description'] ?? '');

    if ($name === '') throw new Exception('Name is required.');

    if ($deptId !== null) {
        $deptCheck = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ? AND status = 'active'");
        $deptCheck->execute([$deptId]);
        if (!$deptCheck->fetchColumn()) throw new Exception('Selected department does not exist or is inactive.');
    }

    $dupCheck = $pdo->prepare("SELECT designation_id FROM designations WHERE LOWER(designation_name) = LOWER(?) AND designation_id != ?");
    $dupCheck->execute([$name, $id]);
    if ($dupCheck->fetchColumn()) throw new Exception('A designation with this name already exists.');

    if ($id > 0) {
        $pdo->prepare("UPDATE designations SET designation_name = ?, department_id = ?, pay_grade = ?, description = ?, updated_at = NOW() WHERE designation_id = ?")
            ->execute([$name, $deptId, ($grade !== '' ? $grade : null), ($desc !== '' ? $desc : null), $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated designation", "$name (ID: $id)");
        echo json_encode(['success' => true, 'message' => 'Designation updated.', 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO designations (designation_name, department_id, pay_grade, description, status, created_by, created_at)
                       VALUES (?, ?, ?, ?, 'active', ?, NOW())")
            ->execute([$name, $deptId, ($grade !== '' ? $grade : null), ($desc !== '' ? $desc : null), $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created designation", "$name (ID: $newId)");
        echo json_encode(['success' => true, 'message' => 'Designation created.', 'id' => $newId]);
    }

} catch (Exception $e) {
    error_log('save_designation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
