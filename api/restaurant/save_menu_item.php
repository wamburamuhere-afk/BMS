<?php
/**
 * API: Create / update a restaurant Menu item (restaurant_plan.md Phase 1).
 *
 * POST: warehouse_id, product_id (blank = new), item_type (dish | bought | existing),
 *       product_name, category_id | new_category, selling_price,
 *       cost_price (bought), opening_stock (bought), prep_area (kitchen|bar|none|<station id>),
 *       is_available (1/0), existing_product_id (existing)
 *
 *  - dish     → a no-stock product (is_service = 1) tied to this shop: always sells, no recipe needed.
 *  - bought   → a normal stock product (water, soda); opening stock goes through the same
 *               receiveProductBatch() + postStockAdjustmentGl() path as Add Product.
 *  - existing → an existing product is put on the menu: ONLY the menu flags change, never its
 *               price or other fields, so a retail shop selling it is unaffected.
 *
 * Gate: Restaurant POS + Smart POS on, shop is Restaurant/Hybrid, canEdit('restaurant_pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$user_id      = (int)$_SESSION['user_id'];
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$product_id   = (int)($_POST['product_id'] ?? 0);
$item_type    = (string)($_POST['item_type'] ?? 'dish');
$name         = trim((string)($_POST['product_name'] ?? ''));
$price        = (float)($_POST['selling_price'] ?? 0);
$cost         = max(0, (float)($_POST['cost_price'] ?? 0));
$opening      = max(0, (float)($_POST['opening_stock'] ?? 0));
$prep_area    = trim((string)($_POST['prep_area'] ?? ''));
$is_available = ((string)($_POST['is_available'] ?? '1') === '1') ? 1 : 0;

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if (!restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}
if (!in_array($item_type, ['dish', 'bought', 'existing'], true)) {
    echo json_encode(['success' => false, 'message' => t('Choose what this item is.')]);
    exit;
}

/** Category id from the picker, or a new group name typed in. */
$resolveCategory = function () use ($pdo): ?int {
    $new = trim((string)($_POST['new_category'] ?? ''));
    if ($new !== '') {
        $st = $pdo->prepare("SELECT category_id FROM categories WHERE LOWER(category_name) = LOWER(?) AND type = 'product' AND status = 'active' LIMIT 1");
        $st->execute([$new]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) return $id;
        $pdo->prepare("INSERT INTO categories (category_name, type, status, created_at) VALUES (?, 'product', 'active', NOW())")->execute([$new]);
        return (int)$pdo->lastInsertId();
    }
    $id = (int)($_POST['category_id'] ?? 0);
    return $id > 0 ? $id : null;
};

try {
    $pdo->beginTransaction();

    if ($product_id > 0) {
        // ── Edit an item already on this shop's menu ─────────────────────
        $st = $pdo->prepare("SELECT product_id, is_service, warehouse_id FROM products WHERE product_id = ? AND is_menu_item = 1 AND status = 'active'");
        $st->execute([$product_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception(t('Menu item not found.'));
        if ($name === '') throw new Exception(t('Name is required.'));
        if ($price <= 0) throw new Exception(t('Enter a selling price.'));
        $dup = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND product_id <> ?");
        $dup->execute([$name, $product_id]);
        if ((int)$dup->fetchColumn() > 0) throw new Exception(sprintf(t('An item named "%s" already exists.'), $name));

        $stationId = restaurantPrepAreaId($pdo, $warehouse_id, $prep_area);
        $sets = "product_name = ?, category_id = ?, selling_price = ?, kitchen_station_id = ?, is_available = ?, updated_by = ?";
        $params = [$name, $resolveCategory(), $price, $stationId, $is_available, $user_id];
        if (!(int)$row['is_service']) { $sets .= ", cost_price = ?, purchase_price = ?"; $params[] = $cost; $params[] = $cost; }
        $params[] = $product_id;
        $pdo->prepare("UPDATE products SET $sets WHERE product_id = ?")->execute($params);
        $pdo->commit();
        logActivity($pdo, $user_id, "Updated menu item: $name (ID $product_id)");
        echo json_encode(['success' => true, 'message' => t('Menu item updated.'), 'product_id' => $product_id]);
        exit;
    }

    if ($item_type === 'existing') {
        // ── Put an existing product on the menu (menu flags only) ───────
        $existing = (int)($_POST['existing_product_id'] ?? 0);
        $st = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ? AND status = 'active'");
        $st->execute([$existing]);
        $pname = $st->fetchColumn();
        if ($pname === false) throw new Exception(t('Choose a product.'));
        $pdo->prepare("UPDATE products SET is_menu_item = 1, is_available = ?, updated_by = ? WHERE product_id = ?")
            ->execute([$is_available, $user_id, $existing]);
        // Make it visible on this shop's menu even before it holds stock here.
        $pdo->prepare("UPDATE products SET warehouse_id = ? WHERE product_id = ? AND warehouse_id IS NULL")->execute([$warehouse_id, $existing]);
        $pdo->commit();
        logActivity($pdo, $user_id, "Added product to menu: $pname (ID $existing)");
        echo json_encode(['success' => true, 'message' => t('Added to the menu.'), 'product_id' => $existing]);
        exit;
    }

    // ── New dish / new bought item (shared with the quick start) ─────────
    $newId = restaurantCreateMenuItem($pdo, $warehouse_id, [
        'type'         => $item_type,
        'name'         => $name,
        'price'        => $price,
        'cost'         => $cost,
        'opening'      => $opening,
        'prep_area'    => $prep_area,
        'category_id'  => $resolveCategory(),
        'is_available' => $is_available,
    ], $user_id);

    $pdo->commit();
    logActivity($pdo, $user_id, "Created menu item: $name (ID $newId)");
    echo json_encode(['success' => true, 'message' => t('Menu item added.'), 'product_id' => $newId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!($e instanceof PDOException)) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        error_log('save_menu_item: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    }
}
