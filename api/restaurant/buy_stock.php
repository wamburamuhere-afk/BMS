<?php
/**
 * API: buy stock for a restaurant shop — drinks and ingredients (restaurant focus pass).
 *
 * POST: product_id, warehouse_id, quantity, unit_cost OR total_paid, [selling_price], [date], [supplier_id], [expiry_date]
 * (Smart POS: paid from the default cash account — no account is asked; [paid_from_account_id] is still accepted.)
 * The logic lives in core/restaurant_purchases.php::restaurantBuyStock() (weighted-average cost, ingredients allowed,
 * Dr Inventory / Cr paid-from account, one ledger).
 *
 * Gate: Restaurant POS + Smart POS on, a Restaurant/Hybrid shop in the user's scope, and the same permission the retail
 * Receive Stock uses (pos_restock / adjust_stock, admins always). Retail Receive Stock is not touched.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_purchases.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!hasPermission('adjust_stock') && !hasPermission('pos_restock') && !isAdmin()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}

try {
    // Restaurant costing plan, Phase 3 — keep=1: "Something else" bought for several days (name, unit, quantity,
    // total_paid). Kept as stock (found by name or made a new ingredient) — its cost counts when it is cooked.
    $keep = (string)($_POST['keep'] ?? '') === '1';
    $fn = $keep ? 'restaurantBuyKeep' : 'restaurantBuyStock';
    $r = $fn($pdo, [
        'name'                 => $_POST['name'] ?? '',
        'unit'                 => $_POST['unit'] ?? '',
        'product_id'           => $_POST['product_id'] ?? 0,
        'warehouse_id'         => $warehouse_id,
        'quantity'             => $_POST['quantity'] ?? 0,
        'unit_cost'            => $_POST['unit_cost'] ?? 0,
        'total_paid'           => $_POST['total_paid'] ?? 0,
        'selling_price'        => $_POST['selling_price'] ?? null,
        'date'                 => $_POST['date'] ?? '',
        'supplier_id'          => $_POST['supplier_id'] ?? 0,
        'expiry_date'          => $_POST['expiry_date'] ?? '',
        'paid_from_account_id' => $_POST['paid_from_account_id'] ?? 0,
        'payment'              => $_POST['payment'] ?? 'paid',        // paid (default) | partial | credit
        'amount_paid'          => $_POST['amount_paid'] ?? 0,
        'due_date'             => $_POST['due_date'] ?? '',
    ], (int)$_SESSION['user_id']);
    echo json_encode(['success' => true, 'message' => t('Purchase saved.')] + $r);
} catch (Throwable $e) {
    $msg = $e instanceof PDOException ? t('Database error.') : $e->getMessage();
    if ($e instanceof PDOException) error_log('restaurant buy_stock: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $msg]);
}
