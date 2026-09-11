<?php
// Phase 6 (pos_upgrade_plan.md) — a warehouse_id is now checked against the
// requesting user's own warehouse scope, not just used to filter the query.
/**
 * Simple products API with category and search filters
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Include global configuration and database connection
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';

// Security: Check if user is authenticated
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

try {
    global $pdo;

    if (!$pdo) {
        throw new Exception("Database connection not available.");
    }

    // Get parameters
    $category = isset($_GET['category']) ? intval($_GET['category']) : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. 0/absent means
    // "no group chosen" -> plain products.selling_price, same as before this
    // phase (fully backward compatible).
    $price_group_id = isset($_GET['price_group_id']) ? intval($_GET['price_group_id']) : 0;
    // Phase 31 (pos_upgrade_plan.md §8) — Product Variants. Absent/0 = the
    // normal top-level grid (a variant parent renders as ONE tile with a
    // variant_count badge; its children never appear as separate tiles).
    // Passed and >0 = the picker's own request for one parent's children —
    // completely different WHERE shape, same endpoint, no new file needed.
    $parent_product_id = isset($_GET['parent_product_id']) ? intval($_GET['parent_product_id']) : 0;

    // A specific warehouse must be one this user is actually scoped to;
    // omitting it entirely is only allowed for admins / grant-all users
    // (otherwise this would silently fall back to a company-wide total).
    if ($warehouse_id > 0) {
        if (!userCan('warehouse', $warehouse_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
            exit;
        }
    } elseif (!hasAllWarehouseAccess()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => t('Select a warehouse — you do not have access to view stock across all warehouses.')]);
        exit;
    }

    // Build query using product_stocks for accurate warehouse balance
    $ps_warehouse_filter = $warehouse_id > 0 ? "AND ps.warehouse_id = :warehouse_ps" : "";
    $sm_warehouse_filter = $warehouse_id > 0 ? "AND sm.warehouse_id = :warehouse_sm" : "";
    
    $project_stock_subquery = "0";
    if ($project_id > 0) {
        $project_stock_subquery = "(SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END), 0) 
                                    FROM stock_movements sm 
                                    WHERE sm.product_id = p.product_id 
                                    AND sm.project_id = :project_id 
                                    $sm_warehouse_filter)";
    }

    $sql = "SELECT 
                p.product_id,
                p.product_name,
                p.sku,
                p.barcode,
                p.selling_price,
                p.min_selling_price,
                COALESCE(MAX(promo.price), MAX(pgp.price), p.selling_price) as effective_price,
                p.tax_rate,
                p.is_taxable,
                COALESCE(SUM(ps.stock_quantity), 0) as total_physical,
                -- General Available: Stock in warehouse NOT reserved for ANY project
                COALESCE(SUM(ps.stock_quantity - IFNULL(ps.reserved_quantity, 0)), 0) as general_available,
                $project_stock_subquery as project_stock,
                p.is_service,
                p.category_id,
                p.image_url,
                p.track_serials,
                p.parent_product_id,
                p.variant_attributes,
                (SELECT COUNT(*) FROM products vc WHERE vc.parent_product_id = p.product_id AND vc.status = 'active') as variant_count
            FROM products p
            LEFT JOIN product_stocks ps ON p.product_id = ps.product_id $ps_warehouse_filter"
            . ($price_group_id > 0
                ? " LEFT JOIN product_price_group_prices pgp ON pgp.product_id = p.product_id AND pgp.price_group_id = :price_group_id"
                : " LEFT JOIN product_price_group_prices pgp ON 1=0") .
            // Phase 25 (pos_upgrade_plan.md §9) — an active, in-window promo
            // price wins over the price-group tier; joined the same
            // MAX()-wrapped way as pgp above to stay ONLY_FULL_GROUP_BY-safe.
            " LEFT JOIN product_promotions promo ON promo.product_id = p.product_id
                AND promo.status = 'active'
                AND promo.starts_at <= NOW() AND promo.ends_at >= NOW()" .
            " WHERE p.status = 'active'";

    // Phase 31 (pos_upgrade_plan.md §8) — a variant child never appears as
    // its own top-level tile (it's reached only through its parent's picker);
    // requesting one specific parent's children flips that around entirely —
    // every other filter (category/search/price group) still applies so the
    // children view respects the same grid context the cashier was already in.
    if ($parent_product_id > 0) {
        $sql .= " AND p.parent_product_id = :parent_product_id";
    } else {
        $sql .= " AND p.parent_product_id IS NULL";
    }

    // A specific warehouse was chosen: only list products actually available
    // there — a physical product needs a product_stocks row for THIS
    // warehouse; a service needs its own products.warehouse_id to match
    // exactly (Warehouse is a required field on the non-inventory product
    // create form, so every service is expected to carry one). Without this,
    // the LEFT JOIN above still lets every company-wide product/service
    // through with a zero quantity instead of excluding it.
    if ($warehouse_id > 0) {
        $sql .= " AND (
                    ps.warehouse_id IS NOT NULL
                    OR (p.is_service = 1 AND p.warehouse_id = :warehouse_svc)
                  )";
    }

    $params = [];
    if ($warehouse_id > 0) {
        $params[':warehouse_ps']  = $warehouse_id;
        $params[':warehouse_svc'] = $warehouse_id;
        if ($project_id > 0) $params[':warehouse_sm'] = $warehouse_id;
    }
    if ($project_id > 0) $params[':project_id'] = $project_id;
    
    if ($category > 0) {
        $sql .= " AND p.category_id = :category";
        $params[':category'] = $category;
    }
    
    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($price_group_id > 0) {
        $params[':price_group_id'] = $price_group_id;
    }

    if ($parent_product_id > 0) {
        $params[':parent_product_id'] = $parent_product_id;
    }

    $sql .= " GROUP BY p.product_id ";
    
    // Sort by project stock first if a project is selected
    if ($project_id > 0) {
        $sql .= " ORDER BY (project_stock > 0) DESC, p.product_name ASC ";
    } else {
        $sql .= " ORDER BY p.product_name LIMIT 100";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Phase 26 (pos_upgrade_plan.md §9) — a runtime double-check, not just a
    // sale-time one: if pos_advanced has since been revoked, the POS grid
    // must not show the serial picker at all for a product that was flagged
    // track_serials=1 while the tenant was entitled (process_sale.php would
    // ignore it as a plain quantity line anyway — this keeps the UI honest
    // about what checkout will actually do).
    $serialTrackingEnabled = function_exists('tenantFeatureEnabled') ? tenantFeatureEnabled('pos_advanced') : true;
    // Phase 31 (pos_upgrade_plan.md §8) — variants are gated the same way.
    $variantsEnabled = $serialTrackingEnabled;

    // Process products
    $products = array_map(function($p) use ($project_id, $serialTrackingEnabled, $variantsEnabled) {
        $p['product_id'] = intval($p['product_id']);
        $p['selling_price'] = floatval($p['selling_price']);
        // Phase 14 — the price a cashier actually sees/starts from: the chosen
        // price group's override if one exists for this product, else plain
        // selling_price (identical to $p['selling_price'] when no group chosen).
        $p['effective_price'] = floatval($p['effective_price']);

        $general = floatval($p['general_available']);
        $p_stock = floatval($p['project_stock'] ?? 0);

        // Final Available = General Stock + This Project's Reserved Stock
        $p['stock_quantity'] = $general + $p_stock;
        $p['project_stock'] = $p_stock;

        $p['is_service'] = (bool)$p['is_service'];
        $p['is_taxable'] = (bool)$p['is_taxable'];
        $p['category_id'] = intval($p['category_id']);
        $p['tax_rate'] = (bool)$p['is_taxable'] ? floatval($p['tax_rate'] ?? 0) : 0;
        $p['track_serials'] = $serialTrackingEnabled ? (int)$p['track_serials'] : 0;

        // Phase 31 (pos_upgrade_plan.md §8) — same runtime double-check
        // pattern as track_serials above: a tenant whose pos_advanced
        // entitlement has since been revoked never sees the variant picker,
        // even for a product that has variant children on file.
        $p['parent_product_id'] = $p['parent_product_id'] !== null ? (int)$p['parent_product_id'] : null;
        $p['variant_attributes'] = $p['variant_attributes'] ?? null;
        $p['variant_count'] = $variantsEnabled ? (int)($p['variant_count'] ?? 0) : 0;

        return $p;
    }, $raw_products);
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'count' => count($products),
        'filters' => [
            'category' => $category,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
