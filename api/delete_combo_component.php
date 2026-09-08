<?php
/**
 * api/delete_combo_component.php
 *
 * Phase 23 (pos_upgrade_plan.md §8) — remove one component from a combo.
 * A plain lookup row, not a financial record — hard delete is fine (same
 * reasoning as Phase 14/15's own component-removal endpoints).
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

$stmt = $pdo->prepare("SELECT parent_product_id, component_name FROM product_assembly_components WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

$pdo->prepare("DELETE FROM product_assembly_components WHERE id = ?")->execute([$id]);
logActivity($pdo, $_SESSION['user_id'], "Removed combo component '{$row['component_name']}' from product #{$row['parent_product_id']}");

echo json_encode(['success' => true, 'message' => 'Combo component removed.']);
