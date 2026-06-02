<?php
/**
 * api/assets/get_asset_categories.php
 *
 * Returns the list of active asset categories with all their defaults so the
 * asset form can auto-populate useful_life, method, salvage % etc. when the
 * user picks a category.
 *
 * Response shape:
 *   { success: true, categories: [{ category_id, category_name, tra_class,
 *       default_method, default_useful_life_years, default_annual_rate_percent,
 *       default_salvage_percent, code_prefix, is_depreciable, tax_rate,
 *       gl_asset_account, gl_accum_account, gl_expense_account,
 *       description, status }, ...] }
 *
 * Read-only — gated by canView('assets').
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you do not have permission to view asset categories']);
    exit;
}

try {
    global $pdo;

    $include_archived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
    // Deleted categories (§12 soft delete) are never returned.
    $where = $include_archived ? "status != 'deleted'" : "status = 'active'";

    $stmt = $pdo->query("
        SELECT category_id, category_name, tra_class,
               default_method, default_useful_life_years,
               default_annual_rate_percent, default_salvage_percent,
               code_prefix, is_depreciable, tax_rate,
               gl_asset_account, gl_accum_account, gl_expense_account,
               description, status, sort_order
          FROM asset_categories
         WHERE {$where}
      ORDER BY sort_order ASC, category_name ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalise types so the JS doesn't see decimals as strings.
    foreach ($rows as &$r) {
        $r['category_id']                  = (int)   $r['category_id'];
        $r['default_useful_life_years']    = $r['default_useful_life_years'] !== null ? (int)$r['default_useful_life_years'] : null;
        $r['default_annual_rate_percent']  = $r['default_annual_rate_percent'] !== null ? (float)$r['default_annual_rate_percent'] : null;
        $r['default_salvage_percent']      = (float) $r['default_salvage_percent'];
        $r['is_depreciable']               = (int)   $r['is_depreciable'];
        $r['tax_rate']                     = $r['tax_rate'] !== null ? (float)$r['tax_rate'] : null;
        $r['sort_order']                   = (int)   $r['sort_order'];
    }
    unset($r);

    echo json_encode(['success' => true, 'categories' => $rows]);
} catch (Throwable $e) {
    error_log('get_asset_categories error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
