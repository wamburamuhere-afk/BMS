<?php
/**
 * API: dish recipes (restaurant_plan.md Phase 7).
 *
 * GET  action=get    product_id, warehouse_id → the dish's ingredients (qty per dish, unit, cost, stock here) + cost per dish
 * GET  action=search q, warehouse_id         → Select2: stock items usable as ingredients
 * POST action=save   product_id, warehouse_id, components=JSON [{product_id, qty}]
 *        Replaces the recipe (product_assembly_components) and marks the dish is_combo when it has
 *        ingredients. An empty recipe = the dish sells without stock again (always allowed).
 * POST action=new_ingredient  warehouse_id, name, unit, cost_price
 *        Creates a stock item (not on the menu) to use as an ingredient; stock arrives via Receive Stock.
 *
 * Only Menu dishes (no-stock items on the menu) of a Restaurant/Hybrid shop. Gate: restaurant module
 * on; reads canView('restaurant_pos'), writes canEdit('restaurant_pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }

global $pdo;
$method = $_SERVER['REQUEST_METHOD'];
$src    = $method === 'POST' ? $_POST : $_GET;
$action = (string)($src['action'] ?? '');
$warehouse_id = (int)($src['warehouse_id'] ?? 0);

if ($action === 'search') {
    if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id) || !restaurantShopActive($pdo, $warehouse_id)) { echo json_encode(['results' => []]); exit; }
    $st = $pdo->prepare("SELECT product_id, product_name, COALESCE(cost_price,0) AS cost_price, COALESCE(NULLIF(unit,''), NULLIF(unit_of_measure,''), 'pcs') AS unit
                           FROM products WHERE status = 'active' AND is_service = 0 AND COALESCE(is_combo,0) = 0 AND parent_product_id IS NULL
                            AND product_name LIKE ? ORDER BY product_name LIMIT 30");
    $st->execute(['%' . trim((string)($src['q'] ?? '')) . '%']);
    echo json_encode(['results' => array_map(fn($r) => ['id' => (int)$r['product_id'], 'text' => $r['product_name'] . ' (' . $r['unit'] . ')', 'unit' => $r['unit'], 'cost_price' => (float)$r['cost_price']], $st->fetchAll(PDO::FETCH_ASSOC))]);
    exit;
}

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if (!restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}

/** A Menu dish: no-stock item on the menu. */
$loadDish = function (int $pid) use ($pdo) {
    $st = $pdo->prepare("SELECT product_id, product_name, selling_price FROM products WHERE product_id = ? AND is_menu_item = 1 AND is_service = 1 AND status = 'active'");
    $st->execute([$pid]);
    return $st->fetch(PDO::FETCH_ASSOC);
};

try {
    if ($action === 'get' && $method === 'GET') {
        $dish = $loadDish((int)($src['product_id'] ?? 0));
        if (!$dish) throw new Exception(t('Menu item not found.'));
        $st = $pdo->prepare("SELECT ac.component_product_id AS product_id, p.product_name, COALESCE(NULLIF(ac.unit,''), NULLIF(p.unit,''), 'pcs') AS unit,
                                    ac.qty_per_unit AS qty, COALESCE(p.cost_price,0) AS cost_price,
                                    COALESCE((SELECT SUM(ps.stock_quantity) FROM product_stocks ps WHERE ps.product_id = p.product_id AND ps.warehouse_id = ?),0) AS stock
                               FROM product_assembly_components ac JOIN products p ON p.product_id = ac.component_product_id
                              WHERE ac.parent_product_id = ? ORDER BY p.product_name");
        $st->execute([$warehouse_id, (int)$dish['product_id']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $cost = 0.0;
        foreach ($rows as &$r) {
            $r['product_id'] = (int)$r['product_id']; $r['qty'] = (float)$r['qty']; $r['cost_price'] = (float)$r['cost_price']; $r['stock'] = (float)$r['stock'];
            $cost += $r['qty'] * $r['cost_price'];
        }
        unset($r);
        echo json_encode(['success' => true, 'dish' => $dish, 'components' => $rows, 'cost' => round($cost, 2)]);
        return;
    }

    if ($method !== 'POST') throw new Exception(t('Method not allowed'));
    if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
    csrf_check();
    $uid = (int)$_SESSION['user_id'];

    if ($action === 'save') {
        $dish = $loadDish((int)($src['product_id'] ?? 0));
        if (!$dish) throw new Exception(t('Menu item not found.'));
        $pid  = (int)$dish['product_id'];
        $list = json_decode((string)($src['components'] ?? '[]'), true);
        if (!is_array($list)) throw new Exception(t('Something went wrong.'));
        $clean = [];
        foreach ($list as $c) {
            $cid = (int)($c['product_id'] ?? 0);
            $qty = round((float)($c['qty'] ?? 0), 2);
            if ($cid <= 0 || $qty <= 0 || $cid === $pid) continue;
            $clean[$cid] = ($clean[$cid] ?? 0) + $qty;     // the same ingredient twice → one line
        }
        if ($clean) {
            $ph = implode(',', array_fill(0, count($clean), '?'));
            $chk = $pdo->prepare("SELECT product_id, COALESCE(NULLIF(unit,''), NULLIF(unit_of_measure,''), 'pcs') AS unit FROM products
                                   WHERE product_id IN ($ph) AND status = 'active' AND is_service = 0 AND COALESCE(is_combo,0) = 0");
            $chk->execute(array_keys($clean));
            $units = $chk->fetchAll(PDO::FETCH_KEY_PAIR);
            if (count($units) !== count($clean)) throw new Exception(t('Ingredients must be stock items (not dishes or services).'));
        }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM product_assembly_components WHERE parent_product_id = ?")->execute([$pid]);
        $ins = $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, unit, qty_per_unit, total_qty) VALUES (?, ?, ?, ?, ?)");
        foreach ($clean as $cid => $qty) $ins->execute([$pid, $cid, $units[$cid] ?? 'pcs', $qty, $qty]);
        $pdo->prepare("UPDATE products SET is_combo = ?, updated_by = ? WHERE product_id = ?")->execute([$clean ? 1 : 0, $uid, $pid]);
        $pdo->commit();
        logActivity($pdo, $uid, ($clean ? 'Saved recipe' : 'Removed recipe') . " for menu item: {$dish['product_name']}");
        echo json_encode(['success' => true, 'message' => $clean ? t('Recipe saved.') : t('Recipe removed — the dish sells without stock.')]);
        return;
    }

    if ($action === 'new_ingredient') {
        $name = trim((string)($src['name'] ?? ''));
        $unit = mb_substr(trim((string)($src['unit'] ?? '')) ?: 'kg', 0, 20);
        $cost = max(0, (float)($src['cost_price'] ?? 0));
        if ($name === '') throw new Exception(t('Name is required.'));
        $dup = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
        $dup->execute([$name]);
        if ((int)$dup->fetchColumn() > 0) throw new Exception(sprintf(t('An item named "%s" already exists.'), $name));
        $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price,
                                             is_service, track_inventory, warehouse_id, is_menu_item, status, created_by)
                       VALUES (?, ?, ?, ?, ?, 0, 0, 0, 1, ?, 0, 'active', ?)")
            ->execute([$name, $unit, $unit, $cost, $cost, $warehouse_id, $uid]);
        $id = (int)$pdo->lastInsertId();
        logActivity($pdo, $uid, "Created ingredient: $name (ID $id)");
        echo json_encode(['success' => true, 'message' => t('Ingredient added.'), 'id' => $id, 'text' => "$name ($unit)", 'unit' => $unit, 'cost_price' => $cost]);
        return;
    }

    echo json_encode(['success' => false, 'message' => t('Unknown action.')]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('restaurant recipe: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
