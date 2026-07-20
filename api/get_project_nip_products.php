<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $project_id = intval($_GET['project_id'] ?? 0);
    if (!$project_id) throw new Exception('Missing project_id');

    // Phase D — project-scope gate
    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
        exit();
    }

    // Auto-add project_id column to products if it does not exist yet
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('project_id', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN project_id INT NULL DEFAULT NULL");
    }

    $stmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.sku, p.selling_price, p.cost_price,
               p.status, p.unit, p.description, p.contract_item_no, p.assembly_quantity,
               p.tax_id,
               COALESCE(t.rate_name,'')      AS tax_name,
               COALESCE(t.rate_percentage,0) AS tax_rate,
               (SELECT COUNT(*) FROM product_assembly_components pac
                WHERE pac.parent_product_id = p.product_id) AS component_count
        FROM products p
        LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
        LEFT JOIN warehouses wh ON p.warehouse_id = wh.warehouse_id
        WHERE p.is_service = 1
          AND p.status     != 'deleted'
          AND (p.project_id = ? OR (p.project_id IS NULL AND wh.project_id = ?))" . scopeFilterSqlNullable('warehouse', 'p') . "
        ORDER BY p.product_name ASC
    ");
    $stmt->execute([$project_id, $project_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total    = count($products);
    $active   = count(array_filter($products, fn($p) => $p['status'] === 'active'));
    $inactive = count(array_filter($products, fn($p) => $p['status'] === 'inactive'));

    echo json_encode([
        'success'  => true,
        'products' => $products,
        'stats'    => ['total' => $total, 'active' => $active, 'inactive' => $inactive]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
