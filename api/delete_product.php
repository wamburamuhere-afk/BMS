<?php
// File: api/delete_product.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

error_reporting(0);
ini_set('display_errors', 0);

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isAdmin() && !canDelete('products')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to delete products']);
    exit();
}

$product_id = intval($_POST['product_id'] ?? 0);

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit();
}

// Phase D — project-scope gate (global products with project_id=NULL pass through)
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('products', 'product_id', $product_id);
}

require_once __DIR__ . '/../core/product_delete.php';

try {
    $pdo->beginTransaction();

    // Get product name before deleting (row-locked so a sale/restock can't
    // land between the stock check below and the delete)
    $stmt = $pdo->prepare("SELECT product_name, sku, warehouse_id FROM products WHERE product_id = ? FOR UPDATE");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }

    // Warehouse-scope gate — a user restricted to one warehouse must not
    // delete a product sitting in a different warehouse, even within the
    // same project (global/unwarehoused products pass through, as above).
    if (!empty($product['warehouse_id']) && function_exists('userCan') && !userCan('warehouse', (int)$product['warehouse_id'])) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => isShopLabel() ? 'Access denied: this product is not in your shop scope.' : 'Access denied: this product is not in your warehouse scope.']);
        exit();
    }

    // Check if product has any sales orders referencing it
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_order_items WHERE product_id = ?");
    $stmt->execute([$product_id]);
    if ($stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode([
            'success'  => false,
            'message'  => 'Cannot delete: this product has existing sales orders. Consider deactivating it instead.'
        ]);
        exit();
    }

    // Check purchase orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE product_id = ?");
    $stmt->execute([$product_id]);
    if ($stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode([
            'success'  => false,
            'message'  => 'Cannot delete: this product has existing purchase orders. Consider deactivating it instead.'
        ]);
        exit();
    }

    // Sales documents keep a link to the item for reporting. Deleting it would
    // drop those sales from every product/service report, so deactivate instead.
    // (POS sales are deliberately not checked here — POS behaviour is unchanged.)
    foreach ([
        'quotation_items'   => 'quotations',
        'invoice_items'     => 'invoices',
        'credit_note_items' => 'credit notes',
    ] as $table => $label) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $used = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $used = 0; // table absent on this tenant
        }
        if ($used > 0) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => "Cannot delete: '{$product['product_name']}' appears on $used existing $label. Set it to Inactive instead so past sales stay in the reports."
            ]);
            exit();
        }
    }

    // A product still holding stock in any shop/warehouse can't be deleted —
    // its quantity is brought to zero first. Past POS sales, stock movements,
    // batches and ledger entries are kept (core/product_delete.php).
    $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? FOR UPDATE")->execute([$product_id]);
    $blocker = productStockBlocker($pdo, $product_id, (string)$product['product_name']);
    if ($blocker !== null) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $blocker]);
        exit();
    }

    deleteProductKeepHistory($pdo, $product_id);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Delete product', "deleted product \"{$product['product_name']}\" with id $product_id (SKU {$product['sku']})");

    echo json_encode(['success' => true, 'message' => "Product '{$product['product_name']}' deleted successfully"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
