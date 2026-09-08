<?php
// scope-audit: skip — price group overrides are a global product-pricing lookup (no project/warehouse scope), same as tax_rates
/**
 * API: List products with their selling_price and (if any) their override
 * price in a given price group — feeds the price-grid on price_groups.php.
 * GET: price_group_id, search (optional)
 * Permission: canView('pos_config_settings').
 * Entitlement: Phase 14 (pos_upgrade_plan.md §8) — gated behind 'pos_advanced'.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())              { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))        { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Price groups are not included in your plan.')]); exit; }
if (!canView('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

global $pdo;

$price_group_id = (int)($_GET['price_group_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

if ($price_group_id <= 0) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

try {
    $sql = "SELECT p.product_id, p.product_name, p.sku, p.selling_price, pgp.price AS override_price
            FROM products p
            LEFT JOIN product_price_group_prices pgp ON pgp.product_id = p.product_id AND pgp.price_group_id = ?
            WHERE p.status = 'active' AND p.is_service = 0";
    $params = [$price_group_id];

    if ($search !== '') {
        $sql .= " AND (p.product_name LIKE ? OR p.sku LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY p.product_name ASC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['product_id'] = (int)$r['product_id'];
        $r['selling_price'] = (float)$r['selling_price'];
        $r['override_price'] = $r['override_price'] !== null ? (float)$r['override_price'] : null;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('get_price_group_products error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
