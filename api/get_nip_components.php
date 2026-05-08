<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $product_id = intval($_GET['id'] ?? 0);
    $project_id = intval($_GET['project_id'] ?? 0);

    // Auto-add project_id column to products if it does not exist yet
    static $colChecked = false;
    if (!$colChecked) {
        $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('project_id', $cols)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN project_id INT NULL DEFAULT NULL");
        }
        $colChecked = true;
    }

    if ($product_id) {
        // Fetch components for a single NIP product
        $stmt = $pdo->prepare("
            SELECT
                ac.id,
                ac.parent_product_id,
                pp.product_name AS parent_name,
                ac.component_product_id,
                cp.product_name AS component_name,
                cp.sku,
                ac.unit,
                ac.qty_per_unit,
                ac.total_qty
            FROM product_assembly_components ac
            JOIN products pp ON ac.parent_product_id = pp.product_id
            JOIN products cp ON ac.component_product_id = cp.product_id
            WHERE ac.parent_product_id = ?
            ORDER BY ac.id ASC
        ");
        $stmt->execute([$product_id]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add backward-compat aliases so existing callers (edit modal) still work
        $components = array_map(function($c) {
            $c['product_id']   = $c['component_product_id'];
            $c['product_name'] = $c['component_name'];
            return $c;
        }, $components);

        // Fetch parent product details for view/edit modals
        $parentStmt = $pdo->prepare("
            SELECT p.product_id, p.product_name, p.sku, p.cost_price, p.selling_price,
                   p.status, p.contract_item_no, p.tax_id, p.warehouse_id,
                   COALESCE(t.rate_name,'') AS tax_name,
                   COALESCE(t.rate_percentage,0) AS tax_rate,
                   COALESCE(w.warehouse_name,'') AS warehouse_name,
                   COALESCE(w.project_id,0) AS project_id,
                   COALESCE(pr.project_name,'General') AS project_name
            FROM products p
            LEFT JOIN tax_rates t  ON p.tax_id       = t.rate_id
            LEFT JOIN warehouses w ON p.warehouse_id  = w.warehouse_id
            LEFT JOIN projects  pr ON w.project_id    = pr.project_id
            WHERE p.product_id = ?
        ");
        $parentStmt->execute([$product_id]);
        $parent_product = $parentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'success'        => true,
            'components'     => $components,
            'data'           => $components,
            'parent_product' => $parent_product
        ]);

    } elseif ($project_id) {
        // Fetch ALL components for NIP products linked to this project's warehouses
        $stmt = $pdo->prepare("
            SELECT
                pac.id,
                pac.parent_product_id,
                pp.product_name AS parent_name,
                pac.component_product_id,
                cp.product_name AS component_name,
                pac.unit,
                pac.qty_per_unit,
                pac.total_qty
            FROM product_assembly_components pac
            JOIN products pp ON pac.parent_product_id = pp.product_id
            LEFT JOIN warehouses wh ON pp.warehouse_id = wh.warehouse_id
            JOIN products cp ON pac.component_product_id = cp.product_id
            WHERE (pp.project_id = ? OR (pp.project_id IS NULL AND wh.project_id = ?))
            ORDER BY pp.product_name ASC, pac.id ASC
        ");
        $stmt->execute([$project_id, $project_id]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'components' => $components
        ]);

    } elseif (isset($_GET['all'])) {
        // Fetch ALL components across every NIP product
        $stmt = $pdo->query("
            SELECT
                pac.id,
                pac.parent_product_id,
                pp.product_name AS parent_name,
                pac.component_product_id,
                cp.product_name AS component_name,
                pac.unit,
                pac.qty_per_unit,
                pac.total_qty
            FROM product_assembly_components pac
            JOIN products pp ON pac.parent_product_id = pp.product_id
            JOIN products cp ON pac.component_product_id = cp.product_id
            ORDER BY pp.product_name ASC, pac.id ASC
        ");
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'components' => $components
        ]);

    } else {
        throw new Exception('Missing product ID or project ID.');
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
