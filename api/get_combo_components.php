<?php
/**
 * api/get_combo_components.php
 *
 * Phase 23 (pos_upgrade_plan.md §8) — list a combo product's components
 * (product_edit.php's "Combo Components" grid).
 * GET: product_id
 * Permission: canView('products')
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/pos_combo_products.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('products')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['success' => false, 'message' => 'Invalid product ID']); exit; }

// Project-scope (security.md §23) — products.project_id exists; a non-admin
// must not see combo components for a product tagged to a project they are
// not assigned to. No-ops for a global (project_id NULL) product.
assertScopeForRecord('products', 'product_id', $product_id);

// Need the row id (for delete) alongside getComboComponents()'s summary shape.
$stmt = $pdo->prepare("
    SELECT ac.id, ac.component_product_id, p.product_name, p.sku, ac.qty_per_unit
    FROM product_assembly_components ac
    JOIN products p ON p.product_id = ac.component_product_id
    WHERE ac.parent_product_id = ?
    ORDER BY p.product_name ASC
");
$stmt->execute([$product_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['component_product_id'] = (int)$r['component_product_id'];
    $r['qty_per_unit'] = (float)$r['qty_per_unit'];
}

echo json_encode(['success' => true, 'data' => $rows]);
