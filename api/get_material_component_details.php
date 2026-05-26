<?php
// scope-audit: skip — NIP material component detail lookup; parent material list scoped by project at creation
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $id       = intval($_GET['id'] ?? 0);
    $for_edit = !empty($_GET['for_edit']);
    if (!$id) throw new Exception('Invalid component ID.');

    // Component info + status
    $compStmt = $pdo->prepare("
        SELECT cp.product_id, cp.product_name, cp.sku,
               COALESCE(nmcs.status, 'pending') AS status
        FROM products cp
        LEFT JOIN nip_material_component_status nmcs ON nmcs.component_product_id = cp.product_id
        WHERE cp.product_id = ?
    ");
    $compStmt->execute([$id]);
    $component = $compStmt->fetch(PDO::FETCH_ASSOC);
    if (!$component) throw new Exception('Component not found.');

    if ($for_edit) {
        // ── Edit mode: one row per pac.id, LEFT JOIN material lists ──────────
        $bomStmt = $pdo->prepare("
            SELECT
                pac.id AS bom_id,
                pac.parent_product_id,
                pp.product_name AS nip_name,
                pac.qty_per_unit,
                COALESCE(pac.unit, '') AS bom_unit,
                COALESCE(SUM(mln.quantity), 0) AS total_nip_qty,
                ROUND(pac.qty_per_unit * COALESCE(SUM(mln.quantity), 0), 4) AS contribution
            FROM product_assembly_components pac
            JOIN products pp ON pac.parent_product_id = pp.product_id
            LEFT JOIN nip_material_list_nips mln ON mln.nip_product_id = pac.parent_product_id
            LEFT JOIN nip_material_lists ml ON mln.material_list_id = ml.id
            WHERE pac.component_product_id = ?
            GROUP BY pac.id, pac.parent_product_id, pp.product_name, pac.qty_per_unit, pac.unit
            ORDER BY pp.product_name ASC
        ");
        $bomStmt->execute([$id]);
        $bom_entries = $bomStmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // ── View mode: one row per NIP (grouped), only NIPs in material lists ─
        $bomStmt = $pdo->prepare("
            SELECT
                pp.product_id AS parent_product_id,
                pp.product_name AS nip_name,
                MAX(pac.unit) AS bom_unit,
                SUM(pac.qty_per_unit) AS qty_per_unit,
                COALESCE(lq.total_nip_qty, 0) AS total_nip_qty,
                ROUND(SUM(pac.qty_per_unit) * COALESCE(lq.total_nip_qty, 0), 4) AS contribution
            FROM product_assembly_components pac
            JOIN products pp ON pac.parent_product_id = pp.product_id
            INNER JOIN (
                SELECT nip_product_id, SUM(quantity) AS total_nip_qty
                FROM nip_material_list_nips
                GROUP BY nip_product_id
            ) lq ON lq.nip_product_id = pac.parent_product_id
            WHERE pac.component_product_id = ?
            GROUP BY pp.product_id, pp.product_name
            ORDER BY pp.product_name ASC
        ");
        $bomStmt->execute([$id]);
        $bom_entries = $bomStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $total_quantity = array_sum(array_column($bom_entries, 'contribution'));

    echo json_encode([
        'success'        => true,
        'component'      => $component,
        'bom_entries'    => $bom_entries,
        'total_quantity' => round($total_quantity, 4)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
