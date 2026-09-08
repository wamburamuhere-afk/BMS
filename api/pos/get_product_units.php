<?php
/**
 * api/pos/get_product_units.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — a product's extra selling units, for
 * the POS quick-view modal's unit selector. Base `pos` access (till hygiene
 * feature, not gated behind pos_advanced — see pos_upgrade_plan.md §8's
 * Phase 15 gate note).
 * GET: product_id
 */
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos')) { echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

global $pdo;
$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

try {
    $stmt = $pdo->prepare("SELECT unit_label, base_unit_multiplier, unit_price_override FROM product_unit_conversions WHERE product_id = ? ORDER BY unit_label ASC");
    $stmt->execute([$product_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['base_unit_multiplier'] = (float)$r['base_unit_multiplier'];
        $r['unit_price_override'] = $r['unit_price_override'] !== null ? (float)$r['unit_price_override'] : null;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('pos/get_product_units error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => []]); // fail-open to base-unit-only, never blocks the sale
}
