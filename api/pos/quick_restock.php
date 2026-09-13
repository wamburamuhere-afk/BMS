<?php
/**
 * API: POS "Restock Product" — a fast, GRN-free way to log stock that has
 * already physically arrived and already been paid for (a shortcut alongside
 * the full PO -> GRN -> approval workflow, not a replacement for it).
 *
 * POST: product_id, warehouse_id (only required when the user is scoped to
 *       more than one warehouse — auto-resolved otherwise), quantity, date
 *       (default today), buying_price, wholesale_price (optional), selling_price,
 *       paid_from_account_id.
 *
 * Creates one product_batches row (so FEFO consumption + the product's
 * Batches/Lots view pick it up exactly like a GRN-sourced batch), updates the
 * live prices a customer is actually charged (products.selling_price for
 * Retail, product_price_group_prices for Wholesale), and posts the buying
 * price as an already-paid cash/bank outflow — post_principle.md's "assume
 * already paid" instruction: Dr Inventory / Cr the selected Paid-From
 * account, via the same postOutflow() used by Expenses/Petty Cash/Payroll,
 * not the Opening-Balance-Equity treatment a no-payment stock correction uses.
 *
 * Permission: adjust_stock (same gate the Products page's own stock
 * adjustment already uses — this is a stock/pricing action that happens to
 * be reachable from the POS screen, not a sales action).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/stock_intake.php';
require_once __DIR__ . '/../../core/pos_price_groups.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/gl_accounts.php';
require_once __DIR__ . '/../../core/code_generator.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())        { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!hasPermission('adjust_stock') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Permission denied')]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$product_id            = (int)($_POST['product_id'] ?? 0);
$warehouse_id           = (int)($_POST['warehouse_id'] ?? 0);
$quantity               = (float)($_POST['quantity'] ?? 0);
$date                   = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d');
$buying_price           = (float)($_POST['buying_price'] ?? 0);
$wholesale_price_raw    = $_POST['wholesale_price'] ?? '';
$selling_price          = (float)($_POST['selling_price'] ?? 0);
$paid_from_account_id   = (int)($_POST['paid_from_account_id'] ?? 0);

if ($product_id <= 0) { echo json_encode(['success' => false, 'message' => t('Select a product.')]); exit; }
if ($quantity <= 0)   { echo json_encode(['success' => false, 'message' => t('Quantity must be greater than zero.')]); exit; }
if ($buying_price < 0 || $selling_price < 0) { echo json_encode(['success' => false, 'message' => t('Prices cannot be negative.')]); exit; }
if ($selling_price <= 0) { echo json_encode(['success' => false, 'message' => t('Retail price is required.')]); exit; }
if ($paid_from_account_id <= 0) { echo json_encode(['success' => false, 'message' => t('Select where this was paid from.')]); exit; }

// Resolve the warehouse server-side — never trust a single-shop assumption
// from the client. Mirrors the exact narrowing pos.php's own warehouse
// dropdown already applies (core/warehouse_scope.php).
require_once __DIR__ . '/../../core/warehouse_scope.php';
$scopedWarehouses = array_values(array_filter(
    warehousesForSelect($pdo),
    fn($w) => userCan('warehouse', (int)$w['warehouse_id'])
));
if ($warehouse_id <= 0) {
    if (count($scopedWarehouses) === 1) {
        $warehouse_id = (int)$scopedWarehouses[0]['warehouse_id'];
    } elseif (count($scopedWarehouses) === 0) {
        echo json_encode(['success' => false, 'message' => t('No shop is assigned to your account — contact an administrator.')]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => t('Select which shop this stock is for.')]);
        exit;
    }
}
if (!userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

$paidFromValid = false;
foreach (cashBankAccounts($pdo) as $a) {
    if ((int)$a['account_id'] === $paid_from_account_id) { $paidFromValid = true; break; }
}
if (!$paidFromValid) {
    echo json_encode(['success' => false, 'message' => t('Invalid Paid-From account.')]);
    exit;
}

$wholesale_price = ($wholesale_price_raw !== '' && $wholesale_price_raw !== null) ? (float)$wholesale_price_raw : null;
if ($wholesale_price !== null && $wholesale_price < 0) {
    echo json_encode(['success' => false, 'message' => t('Prices cannot be negative.')]);
    exit;
}

try {
    $prod = $pdo->prepare("SELECT product_name, is_service, track_inventory FROM products WHERE product_id = ? AND status != 'deleted'");
    $prod->execute([$product_id]);
    $product = $prod->fetch(PDO::FETCH_ASSOC);
    if (!$product) { echo json_encode(['success' => false, 'message' => t('Product not found')]); exit; }
    if (!empty($product['is_service'])) { echo json_encode(['success' => false, 'message' => t('Services cannot be restocked.')]); exit; }
    if (isset($product['track_inventory']) && !$product['track_inventory']) {
        echo json_encode(['success' => false, 'message' => t('This product does not track inventory.')]);
        exit;
    }

    $inventoryAccountId = inventoryAccountId($pdo);
    if (!$inventoryAccountId) {
        echo json_encode(['success' => false, 'message' => t('Inventory account is not configured — contact your administrator before restocking.')]);
        exit;
    }

    $pdo->beginTransaction();

    $ref = nextCode($pdo, 'ADJ');

    $intake = receiveProductBatch($pdo, [
        'product_id'       => $product_id,
        'warehouse_id'     => $warehouse_id,
        'quantity'         => $quantity,
        'unit_cost'        => $buying_price,
        'write_batch'      => true,
        'wholesale_price'  => $wholesale_price,
        'selling_price'    => $selling_price,
        'receipt_id'       => null,
        'movement_type'    => 'adjustment_in',
        'reference_type'   => 'stock_adjustment',
        'reference_id'     => null,
        'reference_number' => $ref,
        'movement_date'    => $date,
        'created_by'       => $_SESSION['user_id'],
        'notes'            => "POS Restock: {$product['product_name']} x{$quantity}",
    ]);

    // Live prices: Retail -> products.selling_price (what every sale, report,
    // and the Retail price-group's own fallback read); Wholesale -> the
    // Wholesale price-group override (the table POS actually joins at
    // checkout — products.wholesale_price is legacy/unread since Phase 14).
    $pdo->prepare("UPDATE products SET selling_price = ? WHERE product_id = ?")
        ->execute([$selling_price, $product_id]);

    if ($wholesale_price !== null) {
        $wholesaleGroupId = wholesalePriceGroupId($pdo);
        if ($wholesaleGroupId) {
            $pdo->prepare("
                INSERT INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
            ")->execute([$product_id, $wholesaleGroupId, $wholesale_price]);
        }
    }

    // post_principle.md: the buying price is already paid — Dr Inventory /
    // Cr the selected Paid-From cash/bank account, via the same postOutflow()
    // Expenses/Petty Cash/Payroll already use, so it correctly shows as a
    // real cash outflow (Cash Flow report), not an Opening-Balance-Equity plug.
    $totalCost = round($quantity * $buying_price, 2);
    $transactionId = null;
    if ($totalCost > 0) {
        $transactionId = postOutflow(
            $pdo,
            'stock_restock',
            $paid_from_account_id,
            $inventoryAccountId,
            $totalCost,
            $date,
            $ref,
            "POS Restock: {$product['product_name']} x{$quantity} @ " . number_format($buying_price, 2)
        );
        if (!$transactionId) {
            throw new Exception(t('Could not post the payment to the ledger — please check your Chart of Accounts setup.'));
        }
    }

    logActivity($pdo, $_SESSION['user_id'], "Restocked {$product['product_name']} x{$quantity} ({$ref}) — batch #{$intake['batch_id']}");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => t('Product restocked successfully!'),
        'batch_id' => $intake['batch_id'],
        'reference_number' => $ref,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('quick_restock error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: t('Database error.')]);
}
