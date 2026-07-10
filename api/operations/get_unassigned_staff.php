<?php
// scope-audit: skip — unassigned staff list; HR management helper, not project-scoped data
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

try {
    $stmt = $pdo->query("
        SELECT e.employee_id, e.employee_number, e.first_name, e.last_name, ds.designation_name
        FROM employees e
        LEFT JOIN designations ds ON e.designation_id = ds.designation_id
        WHERE (e.project_id IS NULL OR e.project_id = 0) 
        AND e.status = 'active'
        ORDER BY e.first_name, e.last_name ASC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "data" => $data]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
