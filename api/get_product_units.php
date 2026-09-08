<?php
/**
 * api/get_product_units.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — list a product's extra selling-unit
 * conversions (product_edit.php's "Selling Units" grid).
 * GET: product_id
 * Permission: canView('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('products')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['success' => false, 'message' => 'Invalid product ID']); exit; }

$stmt = $pdo->prepare("SELECT id, unit_label, base_unit_multiplier, unit_price_override FROM product_unit_conversions WHERE product_id = ? ORDER BY unit_label ASC");
$stmt->execute([$product_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['base_unit_multiplier'] = (float)$r['base_unit_multiplier'];
    $r['unit_price_override'] = $r['unit_price_override'] !== null ? (float)$r['unit_price_override'] : null;
}

echo json_encode(['success' => true, 'data' => $rows]);
