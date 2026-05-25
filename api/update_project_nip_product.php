<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized access');
    $user_id    = $_SESSION['user_id'];

    if (!canEdit('nip_materials')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to edit project NIP products');
    }

    $product_id = intval($_POST['product_id'] ?? 0);
    $project_id = intval($_POST['project_id'] ?? 0);
    if (!$product_id) throw new Exception('Missing Product ID');
    if (!$project_id) throw new Exception('Missing project_id');

    // Phase D — project-scope gate
    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        throw new Exception('Access denied: project not in your scope.');
    }
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('products', 'product_id', $product_id);
    }

    $pdo->beginTransaction();

    $selling_price = floatval($_POST['selling_price'] ?? 0);
    $discount_rate = !empty($_POST['discount_rate']) ? floatval($_POST['discount_rate']) : 0.00;
    $calculated_min_price = $selling_price - ($selling_price * $discount_rate / 100);

    $allowed_statuses = ['active', 'approved', 'pending', 'draft', 'inactive'];
    $status_val = trim($_POST['status'] ?? 'active');
    if (!in_array($status_val, $allowed_statuses)) $status_val = 'active';

    $stmt = $pdo->prepare("
        UPDATE products SET
            product_name      = :product_name,
            description       = :description,
            tax_id            = :tax_id,
            cost_price        = :cost_price,
            selling_price     = :selling_price,
            min_selling_price = :min_selling_price,
            contract_item_no  = :contract_item_no,
            assembly_quantity = :assembly_quantity,
            warehouse_id      = NULL,
            project_id        = :project_id,
            status            = :status
        WHERE product_id      = :product_id
    ");
    $stmt->execute([
        'product_name'      => trim($_POST['product_name'] ?? ''),
        'description'       => !empty($_POST['description'])     ? trim($_POST['description'])     : null,
        'tax_id'            => !empty($_POST['tax_id'])           ? intval($_POST['tax_id'])        : null,
        'cost_price'        => floatval($_POST['cost_price'] ?? 0),
        'selling_price'     => $selling_price,
        'min_selling_price' => !empty($_POST['min_selling_price']) ? floatval($_POST['min_selling_price']) : $calculated_min_price,
        'contract_item_no'  => !empty($_POST['contract_item_no']) ? trim($_POST['contract_item_no']) : null,
        'assembly_quantity' => !empty($_POST['assembly_quantity']) ? floatval($_POST['assembly_quantity']) : 1.00,
        'project_id'        => $project_id,
        'status'            => $status_val,
        'product_id'        => $product_id,
    ]);

    if (empty(trim($_POST['product_name'] ?? ''))) throw new Exception('Product name is required');

    $pdo->prepare("DELETE FROM product_assembly_components WHERE parent_product_id = ?")->execute([$product_id]);

    if (isset($_POST['components']) && is_array($_POST['components'])) {
        $compStmt = $pdo->prepare("
            INSERT INTO product_assembly_components
                (parent_product_id, component_product_id, unit, qty_per_unit, total_qty)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($_POST['components'] as $comp) {
            if (empty($comp['product_id'])) continue;
            $compStmt->execute([
                $product_id,
                $comp['product_id'],
                $comp['unit'] ?? 'EA',
                floatval($comp['qty_per_unit'] ?? 1),
                floatval($comp['total_qty'] ?? 1),
            ]);
        }
    }

    $pdo->commit();

    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Updated Project NIP Product ID $product_id");

    echo json_encode(['success' => true, 'message' => 'Product updated successfully!']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
