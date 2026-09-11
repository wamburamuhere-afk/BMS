<?php
// scope-audit: skip — pos_sales_targets rows are scoped per-request via userCan('warehouse', ...) below, not a blanket list query
/**
 * API: Create/Update a POS Sales Target (Phase 29, pos_upgrade_plan.md §9)
 * POST: warehouse_id (0 = All Warehouses), period_month (YYYY-MM), target_amount
 * Permission: canEdit('pos_advanced') — a management-set goal, same
 * entitlement boundary as Phase 14/18's other genuine analytics capabilities.
 * A non-admin may only set a target for a warehouse they're actually
 * scoped to, or the company-wide (0) row if they have all-warehouse access.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())        { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Sales Targets are not included in your plan.')]); exit; }
if (!canEdit('pos_advanced'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$warehouse_id  = (int)($_POST['warehouse_id'] ?? 0);
$period_month  = trim($_POST['period_month'] ?? ''); // 'YYYY-MM'
$target_amount = (float)($_POST['target_amount'] ?? -1);

if (!preg_match('/^\d{4}-\d{2}$/', $period_month)) {
    echo json_encode(['success' => false, 'message' => t('A valid target month is required.')]); exit;
}
$period_month_date = $period_month . '-01';

if ($target_amount < 0) {
    echo json_encode(['success' => false, 'message' => t('Target amount is required.')]); exit;
}

if ($warehouse_id > 0) {
    if (!userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
        exit;
    }
} elseif (!hasAllWarehouseAccess()) {
    // warehouse_id = 0 means "company-wide" — only a caller with all-warehouse
    // access (admin or an explicit grant-all override) may set that target.
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: setting a company-wide target requires all-warehouse access.')]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO pos_sales_targets (warehouse_id, user_id, period_month, target_amount, created_by)
        VALUES (?, 0, ?, ?, ?)
        ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount), created_by = VALUES(created_by)
    ");
    $stmt->execute([$warehouse_id, $period_month_date, $target_amount, $_SESSION['user_id']]);

    logActivity($pdo, $_SESSION['user_id'], "Set POS sales target: warehouse=$warehouse_id, month=$period_month, amount=$target_amount");
    logAudit($pdo, $_SESSION['user_id'], 'pos_sales_target_set', [
        'entity_type' => 'pos_sales_target',
        'entity_id'   => $warehouse_id,
        'new_values'  => ['warehouse_id' => $warehouse_id, 'period_month' => $period_month_date, 'target_amount' => $target_amount],
    ]);

    echo json_encode(['success' => true, 'message' => t('Sales target saved.')]);
} catch (PDOException $e) {
    error_log('save_sales_target: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
