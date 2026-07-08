<?php
// File: api/get_reporting_to_options.php
// Reporting-To candidates for the employee form, scoped to a department:
//   - if the department has a LEADER set   -> [leader, assistant?] (pick one)
//   - if it has NO leader set               -> all active employees in that dept
// Returns select2-ready { success, mode, results:[{id,text}] }.
ini_set('display_errors', 0);
require_once __DIR__ . '/../roots.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access', 'results' => []]);
    exit();
}

$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$exclude_id    = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0; // the employee being edited
if (!$department_id) {
    echo json_encode(['success' => true, 'mode' => 'none', 'results' => []]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT manager_id, assistant_manager_id FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dept) {
        echo json_encode(['success' => true, 'mode' => 'none', 'results' => []]);
        exit();
    }

    $results = [];

    if (!empty($dept['manager_id'])) {
        // Leadership is set — offer the leader (+ assistant) only.
        $ids = [];
        $roles = [];
        $ids[] = (int)$dept['manager_id'];  $roles[(int)$dept['manager_id']] = 'Leader';
        if (!empty($dept['assistant_manager_id'])) {
            $ids[] = (int)$dept['assistant_manager_id']; $roles[(int)$dept['assistant_manager_id']] = 'Assistant Leader';
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT employee_id, first_name, last_name FROM employees WHERE employee_id IN ($in)");
        $q->execute($ids);
        $byId = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['employee_id']] = $r;
        foreach ($ids as $id) {                    // keep leader-first ordering
            if ($id === $exclude_id || !isset($byId[$id])) continue;
            $r = $byId[$id];
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $results[] = ['id' => $id, 'text' => $name . ' — ' . $roles[$id], 'name' => $name, 'role' => $roles[$id]];
        }
        echo json_encode(['success' => true, 'mode' => 'leadership', 'results' => $results]);
        exit();
    }

    // No leader — everyone in that department (scope-filtered), pick who to report to.
    $scope = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
    $sql = "SELECT e.employee_id, e.first_name, e.last_name
            FROM employees e
            WHERE e.department_id = ?
              AND (e.status IS NULL OR e.status != 'deleted')" . $scope . "
            ORDER BY e.first_name, e.last_name";
    $q = $pdo->prepare($sql);
    $q->execute([$department_id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['employee_id'];
        if ($id === $exclude_id) continue;         // can't report to yourself
        $name = trim($r['first_name'] . ' ' . $r['last_name']);
        $results[] = ['id' => $id, 'text' => $name, 'name' => $name, 'role' => ''];
    }
    echo json_encode(['success' => true, 'mode' => 'all', 'results' => $results]);
} catch (Exception $e) {
    error_log("get_reporting_to_options: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred', 'results' => []]);
}
