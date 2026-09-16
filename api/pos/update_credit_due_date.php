<?php
// scope-audit: skip — acts on one POS sale by primary key under canEdit('pos'); warehouse scope enforced below
/**
 * API: Edit a credit sale's due date / notes
 * ----------------------------------------------------------------------------
 * pos_credit_receivables_plan.md Phase 2a/2b — the "Edit" action on the
 * receivables aging page / Madeni tab. Deliberately narrow: only due_date
 * and internal_notes are writable here, never amount/customer/items — those
 * are the sale's financial facts, already enforced correct at sale time
 * (process_sale.php) and reversible only through void_sale.php, which also
 * reverses stock/cash/GL. This endpoint touches neither.
 *
 * POST (form-encoded): sale_id, due_date (YYYY-MM-DD), notes?
 * Permission: canEdit('pos')
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('pos'))         { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This action is only available in Simple POS mode.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$sale_id  = (int)($_POST['sale_id'] ?? 0);
$due_date = trim($_POST['due_date'] ?? '');
$notes    = trim($_POST['notes'] ?? '');

if ($sale_id <= 0) { echo json_encode(['success' => false, 'message' => t('Invalid sale.')]); exit; }
if ($due_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
    echo json_encode(['success' => false, 'message' => t('Please choose a valid due date.')]); exit;
}

try {
    global $pdo;

    $st = $pdo->prepare("SELECT warehouse_id, payment_method, sale_status, receipt_number FROM pos_sales WHERE sale_id = ?");
    $st->execute([$sale_id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success' => false, 'message' => t('Sale not found.')]); exit; }
    if ($sale['payment_method'] !== 'credit') { echo json_encode(['success' => false, 'message' => t('This is not a credit sale.')]); exit; }
    if ($sale['sale_status'] === 'voided')    { echo json_encode(['success' => false, 'message' => t('This sale is voided.')]); exit; }

    $wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
    if ($wid !== null && !userCan('warehouse', $wid)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => isShopLabel(true)
            ? t('Access denied: this shop is not in your assigned scope.')
            : t('Access denied: this warehouse is not in your assigned scope.')]);
        exit;
    }

    $pdo->prepare("UPDATE pos_sales SET due_date = ?, internal_notes = ?, updated_at = NOW() WHERE sale_id = ?")
        ->execute([$due_date, ($notes !== '' ? $notes : null), $sale_id]);

    logActivity($pdo, $_SESSION['user_id'], "Updated due date on credit Sale #{$sale['receipt_number']} to {$due_date}");
    logAudit($pdo, $_SESSION['user_id'], 'pos_credit_due_date_updated', [
        'entity_type' => 'pos_sale', 'entity_id' => $sale_id,
        'new_values'  => ['due_date' => $due_date],
    ]);

    echo json_encode(['success' => true, 'message' => t('Due date updated.')]);
} catch (Throwable $e) {
    error_log('update_credit_due_date: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
