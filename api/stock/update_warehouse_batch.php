<?php
/**
 * API: correct a batch's own recorded details — "Batches" tab on
 * warehouse_view.php. Simple POS only.
 * ----------------------------------------------------------------------------
 * Deliberately editable fields: batch_number, manufacturing_date,
 * expiry_date, unit_cost (buying price), wholesale_price, selling_price —
 * this batch's OWN historical record as it should have been captured at
 * intake time. This is a correction of that record, nothing else:
 *
 *   - quantity_received / quantity_remaining are NEVER editable here. They
 *     are the live output of core/pos_batch_consumption.php (FEFO
 *     consumption on every sale, exact restoration on every void/return)
 *     and core/stock_intake.php (every restock/GRN/initial-stock intake).
 *     Hand-editing them here would desync product_batches from the real
 *     stock ledger (product_stocks, stock_movements) — the same drift risk
 *     core/pos_batch_consumption.php's own docblock already warns about.
 *     Quantity only ever changes through an actual stock movement.
 *   - selling_price/wholesale_price here are a per-batch PRICE HISTORY
 *     correction only — unlike api/pos/quick_restock.php's NEW-batch intake
 *     (which deliberately also pushes the new price live onto
 *     products.selling_price / the Wholesale price group), fixing an
 *     existing batch's recorded price must never silently change what the
 *     product actually sells for today. That stays a deliberate, separate
 *     action on the Product page.
 *
 * POST: batch_id, warehouse_id, batch_number?, manufacturing_date?,
 *       expiry_date?, unit_cost, wholesale_price?, selling_price?
 * Permission: canEdit('warehouses') + userCan('warehouse', $warehouse_id)
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('warehouses'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$batchId     = (int)($_POST['batch_id'] ?? 0);
$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
if ($batchId <= 0 || $warehouseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('A valid batch and warehouse are required.')]);
    exit;
}
if (!userCan('warehouse', $warehouseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    global $pdo;

    // The batch must genuinely belong to this warehouse — never trust the
    // client-supplied warehouse_id alone to authorise editing an arbitrary
    // batch_id from a warehouse the caller isn't scoped to.
    $check = $pdo->prepare("SELECT batch_id FROM product_batches WHERE batch_id = ? AND warehouse_id = ?");
    $check->execute([$batchId, $warehouseId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('Batch not found in this warehouse.')]);
        exit;
    }

    $batchNumber = trim((string)($_POST['batch_number'] ?? ''));
    $mfgDate     = trim((string)($_POST['manufacturing_date'] ?? ''));
    $expiryDate  = trim((string)($_POST['expiry_date'] ?? ''));
    $unitCost    = $_POST['unit_cost'] ?? '';
    $wholesale   = trim((string)($_POST['wholesale_price'] ?? ''));
    $selling     = trim((string)($_POST['selling_price'] ?? ''));

    if ($unitCost === '' || !is_numeric($unitCost) || (float)$unitCost < 0) {
        echo json_encode(['success' => false, 'message' => t('Buying price must be a valid, non-negative number.')]);
        exit;
    }
    foreach (['manufacturing_date' => $mfgDate, 'expiry_date' => $expiryDate] as $label => $val) {
        if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            echo json_encode(['success' => false, 'message' => t('Dates must be in YYYY-MM-DD format.')]);
            exit;
        }
    }
    if ($wholesale !== '' && (!is_numeric($wholesale) || (float)$wholesale < 0)) {
        echo json_encode(['success' => false, 'message' => t('Wholesale price must be a valid, non-negative number.')]);
        exit;
    }
    if ($selling !== '' && (!is_numeric($selling) || (float)$selling < 0)) {
        echo json_encode(['success' => false, 'message' => t('Selling price must be a valid, non-negative number.')]);
        exit;
    }

    $pdo->prepare("
        UPDATE product_batches
           SET batch_number = ?, manufacturing_date = ?, expiry_date = ?,
               unit_cost = ?, wholesale_price = ?, selling_price = ?, updated_at = NOW()
         WHERE batch_id = ? AND warehouse_id = ?
    ")->execute([
        $batchNumber !== '' ? $batchNumber : null,
        $mfgDate !== '' ? $mfgDate : null,
        $expiryDate !== '' ? $expiryDate : null,
        (float)$unitCost,
        $wholesale !== '' ? (float)$wholesale : null,
        $selling !== '' ? (float)$selling : null,
        $batchId,
        $warehouseId,
    ]);

    logActivity($pdo, $_SESSION['user_id'], "Updated batch #$batchId details (warehouse #$warehouseId)");
    logAudit($pdo, $_SESSION['user_id'], 'warehouse_batch_edit', [
        'entity_type' => 'product_batch',
        'entity_id'   => $batchId,
        'new_values'  => [
            'batch_number' => $batchNumber, 'manufacturing_date' => $mfgDate, 'expiry_date' => $expiryDate,
            'unit_cost' => $unitCost, 'wholesale_price' => $wholesale, 'selling_price' => $selling,
        ],
    ]);

    echo json_encode(['success' => true, 'message' => t('Batch updated successfully.')]);
} catch (Throwable $e) {
    error_log('update_warehouse_batch: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
