<?php
/**
 * API: restaurant pay-later — suppliers you owe (restaurant pay-later, 2026-11-02).
 *
 * GET  action=debts&warehouse_id=        → open bills (what is still owed) for that restaurant shop
 * GET  action=payments&bill_id=          → payments made on one bill
 * POST action=add_supplier               → name, [phone]  (a supplier with only a name and a phone number)
 * POST action=buy_grocery                → "Something else" bought partly paid / on credit:
 *                                          name, amount, warehouse_id, date, supplier_id, payment, [amount_paid], [due_date]
 * POST action=pay                        → bill_id, amount, [date]  (pay part or all of what is owed, from cash)
 *
 * Logic: core/restaurant_supplier_credit.php (one payable, shared with the Smart BMS supplier bills).
 * Gate: Restaurant POS + Smart POS, a restaurant shop in the user's scope, and the Purchases permission.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_supplier_credit.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!restaurantCanBuy())        { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

global $pdo;
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$uid    = (int)$_SESSION['user_id'];

/** A restaurant shop in the user's scope, or stop. */
$shop = function (int $wh) use ($pdo): void {
    if ($wh <= 0 || !userCan('warehouse', $wh) || !restaurantShopActive($pdo, $wh)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => t('Access denied: this shop is not in your assigned scope.')]);
        exit;
    }
};
/** The bill must belong to a restaurant shop the user can see. */
$billShop = function (int $billId) use ($pdo, $shop): void {
    $st = $pdo->prepare("SELECT warehouse_id FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $st->execute([$billId]);
    $wh = $st->fetchColumn();
    if ($wh === false) { echo json_encode(['success' => false, 'message' => t('Bill not found.')]); exit; }
    $shop((int)$wh);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'debts') {
            $wh = (int)($_GET['warehouse_id'] ?? 0);
            $shop($wh);
            $rows = restaurantSupplierDebts($pdo, [$wh]);
            echo json_encode(['success' => true, 'data' => $rows, 'total' => round(array_sum(array_column($rows, 'owed')), 2)]);
            exit;
        }
        if ($action === 'payments') {
            $id = (int)($_GET['bill_id'] ?? 0);
            $billShop($id);
            $st = $pdo->prepare("SELECT payment_date, amount FROM supplier_invoice_payments WHERE invoice_id = ? ORDER BY payment_date, id");
            $st->execute([$id]);
            echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => t('Unknown action')]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
    csrf_check();

    if ($action === 'add_supplier') {
        $r = restaurantQuickAddSupplier($pdo, (string)($_POST['name'] ?? ''), (string)($_POST['phone'] ?? ''), $uid);
        echo json_encode(['success' => true] + $r);
        exit;
    }
    if ($action === 'buy_grocery') {
        $wh = (int)($_POST['warehouse_id'] ?? 0);
        $shop($wh);
        $r = restaurantBuyGroceryOnCredit($pdo, [
            'name' => $_POST['name'] ?? '', 'amount' => $_POST['amount'] ?? 0, 'warehouse_id' => $wh, 'date' => $_POST['date'] ?? '',
            'supplier_id' => $_POST['supplier_id'] ?? 0, 'payment' => $_POST['payment'] ?? 'credit',
            'amount_paid' => $_POST['amount_paid'] ?? 0, 'due_date' => $_POST['due_date'] ?? '',
        ], $uid);
        echo json_encode(['success' => true, 'message' => t('Purchase saved.')] + $r);
        exit;
    }
    if ($action === 'pay') {
        $id = (int)($_POST['bill_id'] ?? 0);
        $billShop($id);
        $r = restaurantPaySupplierBill($pdo, $id, (float)($_POST['amount'] ?? 0), (string)($_POST['date'] ?? date('Y-m-d')), 0, $uid);
        echo json_encode(['success' => true, 'message' => $r['status'] === 'paid' ? t('Paid in full.') : t('Payment saved.')] + $r);
        exit;
    }
    echo json_encode(['success' => false, 'message' => t('Unknown action')]);
} catch (Throwable $e) {
    $msg = $e instanceof PDOException ? t('Database error.') : $e->getMessage();
    if ($e instanceof PDOException) error_log('restaurant supplier_credit: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $msg]);
}
