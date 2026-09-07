<?php
// scope-audit: skip — registers are a small global lookup table (no project/warehouse scope), same as tax_rates/brands
/**
 * API: Activate/Deactivate a POS Register (Till)
 * A register is never hard-deleted (past shifts reference it by register_id) —
 * deactivating just removes it from the Open Shift picker.
 * POST: register_id, status ('active'|'inactive')
 * Permission: canEdit('pos_config_settings')
 * Entitlement: Phase 13 (pos_upgrade_plan.md §7) — gated behind 'pos_advanced'.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isAuthenticated())              { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos_advanced'))        { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Multi-register management is not included in your plan.']); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;

$register_id = (int)($_POST['register_id'] ?? 0);
$status      = $_POST['status'] ?? '';

if ($register_id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$stmt = $pdo->prepare("SELECT register_name FROM pos_registers WHERE register_id = ?");
$stmt->execute([$register_id]);
$name = $stmt->fetchColumn();
if (!$name) {
    echo json_encode(['success' => false, 'message' => 'Register not found.']);
    exit;
}

// A register with an open shift right now must not be deactivated out from
// under an active cashier.
if ($status === 'inactive') {
    $active = $pdo->prepare("SELECT COUNT(*) FROM cash_register_shifts WHERE register_id = ? AND status = 'active'");
    $active->execute([$register_id]);
    if ($active->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot deactivate: this register has an open shift right now.']);
        exit;
    }
}

$pdo->prepare("UPDATE pos_registers SET status = ?, updated_at = NOW() WHERE register_id = ?")
    ->execute([$status, $register_id]);

$verb = $status === 'active' ? 'Activated' : 'Deactivated';
logActivity($pdo, $_SESSION['user_id'], "$verb POS register: $name");

echo json_encode(['success' => true, 'message' => "Register " . strtolower($verb) . " successfully."]);
