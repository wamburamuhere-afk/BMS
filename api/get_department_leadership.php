<?php
// File: api/get_department_leadership.php
// Returns the current leader + assistant leader of a department, for the HR
// Actions "Department Leadership" modal (prefill + "current leadership" info).
// scope-audit: skip — leadership is org-structure info; a leader/assistant may
// legitimately sit outside the caller's project scope, so it is not scoped.
ini_set('display_errors', 0);
require_once __DIR__ . '/../roots.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}
if (!canView('employee_lifecycle')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit();
}

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
if (!$department_id) {
    echo json_encode(['success' => false, 'message' => 'Department ID is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT department_id, department_name, manager_id, assistant_manager_id
                           FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dept) {
        echo json_encode(['success' => false, 'message' => 'Department not found']);
        exit();
    }

    $nameOf = function ($empId) use ($pdo) {
        if (empty($empId)) return null;
        $s = $pdo->prepare("SELECT employee_id, first_name, last_name FROM employees WHERE employee_id = ?");
        $s->execute([(int)$empId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['id' => (int)$r['employee_id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name'])];
    };

    echo json_encode([
        'success'         => true,
        'department_id'   => (int)$dept['department_id'],
        'department_name' => $dept['department_name'],
        'leader'          => $nameOf($dept['manager_id']),
        'assistant'       => $nameOf($dept['assistant_manager_id']),
    ]);
} catch (Exception $e) {
    error_log("get_department_leadership: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
