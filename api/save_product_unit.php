<?php
/**
 * api/save_product_unit.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — create/update one selling-unit
 * conversion row for a product.
 * POST: id (blank = create), product_id, unit_label, base_unit_multiplier,
 *       unit_price_override (optional)
 * Permission: canEdit('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('products')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$product_id = (int)($_POST['product_id'] ?? 0);
$unit_label = trim($_POST['unit_label'] ?? '');
$multiplier = (float)($_POST['base_unit_multiplier'] ?? 0);
$priceOverrideRaw = $_POST['unit_price_override'] ?? '';
$unit_price_override = ($priceOverrideRaw === '' || $priceOverrideRaw === null) ? null : (float)$priceOverrideRaw;

if (!$product_id || $unit_label === '' || $multiplier <= 0) {
    echo json_encode(['success' => false, 'message' => 'Product, unit label, and a multiplier greater than zero are required.']);
    exit;
}
if ($unit_price_override !== null && $unit_price_override < 0) {
    echo json_encode(['success' => false, 'message' => 'Price cannot be negative.']);
    exit;
}

// Project-scope (security.md §23) — products.project_id exists; a non-admin
// must not edit selling units for a product tagged to a project they are
// not assigned to. No-ops for a global (project_id NULL) product.
assertScopeForRecord('products', 'product_id', $product_id);

try {
    $stmt = $pdo->prepare("SELECT product_name, unit FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

    if (strcasecmp($unit_label, (string)$product['unit']) === 0) {
        echo json_encode(['success' => false, 'message' => 'This is already the product\'s base unit — add a different selling unit.']);
        exit;
    }

    $dupSql = "SELECT COUNT(*) FROM product_unit_conversions WHERE product_id = ? AND unit_label = ?" . ($id > 0 ? " AND id != ?" : "");
    $dupParams = $id > 0 ? [$product_id, $unit_label, $id] : [$product_id, $unit_label];
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute($dupParams);
    if ($dupStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'This product already has a selling unit with that label.']);
        exit;
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE product_unit_conversions SET unit_label=?, base_unit_multiplier=?, unit_price_override=?, updated_at=NOW() WHERE id=? AND product_id=?")
            ->execute([$unit_label, $multiplier, $unit_price_override, $id, $product_id]);
        $message = 'Selling unit updated.';
    } else {
        $pdo->prepare("INSERT INTO product_unit_conversions (product_id, unit_label, base_unit_multiplier, unit_price_override, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
            ->execute([$product_id, $unit_label, $multiplier, $unit_price_override]);
        $id = (int)$pdo->lastInsertId();
        $message = 'Selling unit added.';
    }

    logActivity($pdo, $_SESSION['user_id'], "Set selling unit '$unit_label' (x$multiplier) for product: {$product['product_name']}");
    echo json_encode(['success' => true, 'message' => $message, 'id' => $id]);

} catch (PDOException $e) {
    error_log('save_product_unit: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
