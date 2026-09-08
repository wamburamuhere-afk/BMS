<?php
/**
 * api/delete_product_unit.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — remove one selling-unit conversion.
 * A plain lookup row, not a financial record — hard delete is fine (mirrors
 * how a price-group override is cleared in Phase 14's save_price_group_product_price.php).
 * POST: id
 * Permission: canEdit('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('products')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }

$stmt = $pdo->prepare("SELECT product_id, unit_label FROM product_unit_conversions WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

$pdo->prepare("DELETE FROM product_unit_conversions WHERE id = ?")->execute([$id]);
logActivity($pdo, $_SESSION['user_id'], "Removed selling unit '{$row['unit_label']}' from product #{$row['product_id']}");

echo json_encode(['success' => true, 'message' => 'Selling unit removed.']);
