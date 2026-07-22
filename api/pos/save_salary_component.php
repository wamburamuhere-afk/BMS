<?php
// api/pos/save_salary_component.php — create/update a reusable salary component.
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('salary_components')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot manage salary components']); exit; }

try {
    $id     = (int)($_POST['component_id'] ?? 0);
    $name   = trim($_POST['component_name'] ?? '');
    $type   = $_POST['component_type'] ?? '';
    $calc   = $_POST['calculation_type'] ?? 'fixed';
    $amount = round((float)($_POST['default_amount'] ?? 0), 2);
    $tax    = !empty($_POST['tax_applicable']) ? 1 : 0;
    $desc   = trim($_POST['description'] ?? '');

    if ($name === '') throw new Exception('Component name is required.');
    if (!in_array($type, ['allowance', 'deduction', 'bonus'], true)) throw new Exception('Invalid component type.');
    if (!in_array($calc, ['fixed', 'percentage', 'formula'], true)) throw new Exception('Invalid calculation type.');
    if ($amount < 0) throw new Exception('Default value cannot be negative.');
    if ($calc === 'percentage' && $amount > 100) throw new Exception('A percentage cannot exceed 100%.');

    if ($id > 0) {
        $pdo->prepare("UPDATE salary_components
                          SET component_name = ?, component_type = ?, calculation_type = ?, default_amount = ?, tax_applicable = ?, description = ?, updated_at = NOW()
                        WHERE component_id = ?")
            ->execute([$name, $type, $calc, $amount, $tax, ($desc !== '' ? $desc : null), $id]);
        logActivity($pdo, $_SESSION['user_id'], "Updated salary component", "$name (ID: $id)");
        echo json_encode(['success' => true, 'message' => 'Component updated.', 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO salary_components (component_name, component_type, calculation_type, default_amount, tax_applicable, description, status, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())")
            ->execute([$name, $type, $calc, $amount, $tax, ($desc !== '' ? $desc : null), $_SESSION['user_id']]);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], "Created salary component", "$name (ID: $newId)");
        echo json_encode(['success' => true, 'message' => 'Component created.', 'id' => $newId]);
    }

} catch (Exception $e) {
    error_log('save_salary_component error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
