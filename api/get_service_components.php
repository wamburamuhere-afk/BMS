<?php
// scope-audit: skip — service component lookup helper; products are global catalog
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $product_id = intval($_GET['product_id'] ?? 0);
    $page       = max(1, intval($_GET['page'] ?? 1));
    $per_page   = max(5, min(100, intval($_GET['per_page'] ?? 10)));

    if (!$product_id) throw new Exception('Invalid product ID.');

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = ?");
    $countStmt->execute([$product_id]);
    $total  = (int)$countStmt->fetchColumn();
    $pages  = $total > 0 ? (int)ceil($total / $per_page) : 1;
    $offset = ($page - 1) * $per_page;

    $stmt = $pdo->prepare("
        SELECT ac.id, ac.component_product_id, ac.unit, ac.qty_per_unit,
               cp.product_name, cp.sku,
               COALESCE(cp.cost_price, 0) AS component_cost
        FROM product_assembly_components ac
        JOIN products cp ON ac.component_product_id = cp.product_id
        WHERE ac.parent_product_id = ?
        ORDER BY cp.product_name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $product_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $per_page,   PDO::PARAM_INT);
    $stmt->bindValue(3, $offset,     PDO::PARAM_INT);
    $stmt->execute();
    $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCostStmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(cp.cost_price, 0) * ac.qty_per_unit), 0)
        FROM product_assembly_components ac
        JOIN products cp ON ac.component_product_id = cp.product_id
        WHERE ac.parent_product_id = ?
    ");
    $totalCostStmt->execute([$product_id]);
    $grand_total = (float)$totalCostStmt->fetchColumn();

    echo json_encode([
        'success'     => true,
        'components'  => $components,
        'pagination'  => [
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'per_page' => $per_page,
        ],
        'grand_total' => round($grand_total, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
