<?php
/**
 * API: "Pika Leo" — the cooking plan (restaurant costing plan, Phase 3). Logic: core/restaurant_cook_plan.php.
 *
 * GET  action=init  warehouse_id               → dishes of the Menu, counted items with stock, today's cooks, rights
 * GET  action=dish  warehouse_id, product_id   → one dish: plates per pot, cost of a plate, recipe ingredients (they
 *                                                leave stock as plates sell), what was taken per pot last time, and
 *                                                the counted items that may be taken (the recipe's ingredients left out)
 * GET  action=day   warehouse_id, date         → the cooks of that day
 * POST action=create warehouse_id, product_id, pots, lines=JSON [{product_id, qty}]   (Restaurant POS edit)
 * POST action=close  plan_id, plates_left, [keep_left=1]                              (Restaurant POS edit)
 *        Phase 5: when every cook of the dish that day is closed, the recipe ingredients of the plates not sold
 *        (not accounted for + left, unless kept to sell later) leave stock as waste — see rcpPostUnsold().
 * POST action=cancel plan_id                                                           (Restaurant POS edit)
 * POST action=spoil  warehouse_id, product_id, qty, [reason]           (adjust_stock / pos_restock, admins)
 * POST action=decide plan_id, decision=approve|reject — a cook's stock request (Phase 4; same stock right, not the asker)
 *   init also returns 'requests' (waiting, for the stock right) and dish returns max_plates / limiting (stock check).
 *
 * Money (costs, expected profit) only for restaurant_costing — everyone else gets plates and amounts.
 * Gate: Restaurant POS + Smart POS on, a Restaurant/Hybrid shop in the user's scope.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_cook_plan.php';

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo) || !rcpReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }

global $pdo;
$method = $_SERVER['REQUEST_METHOD'];
$src    = $method === 'POST' ? $_POST : $_GET;
$action = (string)($src['action'] ?? '');
$uid    = (int)$_SESSION['user_id'];
$seeCost = restaurantCanSeeCost();

/** The shop must be in the user's scope and a restaurant shop. */
$shop = function (int $wh): void {
    global $pdo;
    if ($wh <= 0 || !userCan('warehouse', $wh)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
        exit;
    }
    if (!restaurantShopActive($pdo, $wh)) { echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]); exit; }
};
/** Leave money out for a user without restaurant_costing. */
$strip = function (array $rows, array $keys) use ($seeCost): array {
    if ($seeCost) return $rows;
    foreach ($rows as &$r) foreach ($keys as $k) unset($r[$k]);
    unset($r);
    return $rows;
};
$dayOut = function (int $wh, string $date, bool $earlier = false) use ($pdo, $seeCost, $strip): array {
    $rows = $earlier ? rcpOpenEarlier($pdo, $wh, $date) : rcpDay($pdo, $wh, $date);
    if (!$seeCost) foreach ($rows as &$r) { unset($r['recipe_cost'], $r['stock_cost']); $r['items'] = $strip($r['items'], ['amount']); if ($r['unsold']) unset($r['unsold']['amount']); }
    unset($r);
    return $rows;
};
$rights = fn() => ['can_edit' => rcpCanCook(), 'can_move_stock' => rcpCanMoveStock(), 'can_see_cost' => $seeCost,
                    // Phase 4: someone who may cook, without the stock right, may ask for stock
                    'can_request' => rcpCanCook() && !rcpCanMoveStock() && rcpRequestsReady($pdo)];
/** Phase 4 — waiting stock requests, only for whoever may give stock out. */
$requests = function (int $wh) use ($pdo, $seeCost, $strip): array {
    if (!rcpCanMoveStock()) return [];
    $r = rcpRequests($pdo, $wh);
    if (!$seeCost) foreach ($r as &$q) $q['lines'] = $strip($q['lines'], ['cost_price']);
    unset($q);
    return $r;
};

try {
    if ($method === 'GET') {
        $wh = (int)($src['warehouse_id'] ?? 0);
        $shop($wh);
        if ($action === 'init') {
            echo json_encode(['success' => true, 'today' => date('Y-m-d'),
                'dishes' => $strip(rcpDishes($pdo, $wh), ['plate_cost']),
                'stock'  => $strip(rcpStockItems($pdo, $wh), ['cost_price']),
                'plans'  => $dayOut($wh, date('Y-m-d')), 'requests' => $requests($wh),
                // cooks of earlier days never closed — closed from today's page so their plates not sold are counted
                'earlier' => rcpCanCook() ? $dayOut($wh, date('Y-m-d'), true) : []] + $rights());
            return;
        }
        if ($action === 'dish') {
            $d = rcpDish($pdo, $wh, (int)($src['product_id'] ?? 0));
            if (!$d) throw new Exception(t('Choose a dish from the Menu.'));
            $compIds = array_column($d['components'], 'product_id');
            $out = ['success' => true, 'dish' => $d, 'last' => rcpLastLines($pdo, $wh, $d['product_id']),
                    'stock' => $strip(rcpStockItems($pdo, $wh, $compIds), ['cost_price'])];
            if (!$seeCost) { unset($out['dish']['plate_cost'], $out['dish']['extra']); $out['dish']['components'] = $strip($d['components'], ['cost_price']); }
            echo json_encode($out);
            return;
        }
        if ($action === 'day') {
            $date = (string)($src['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new Exception(t('Enter a valid date.'));
            echo json_encode(['success' => true, 'date' => $date, 'plans' => $dayOut($wh, $date)] + $rights());
            return;
        }
        throw new Exception(t('Unknown action.'));
    }

    if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
    csrf_check();

    if ($action === 'spoil') {
        $wh = (int)($src['warehouse_id'] ?? 0);
        $shop($wh);
        $r = rcpSpoil($pdo, ['warehouse_id' => $wh, 'product_id' => $src['product_id'] ?? 0, 'qty' => $src['qty'] ?? 0, 'reason' => $src['reason'] ?? ''], $uid);
        if (!$seeCost) unset($r['amount']);
        echo json_encode(['success' => true, 'message' => t('Spoiled stock recorded.')] + $r);
        return;
    }

    if ($action === 'decide') {                  // Phase 4 — the stock right answers a cook's request (checked in rcpDecide)
        $p = rcpGet($pdo, (int)($src['plan_id'] ?? 0));
        if ($p) $shop((int)$p['warehouse_id']);
        $approve = (string)($src['decision'] ?? '') === 'approve';
        if (!$approve && (string)($src['decision'] ?? '') !== 'reject') throw new Exception(t('Unknown action.'));
        $r = rcpDecide($pdo, (int)($src['plan_id'] ?? 0), $approve, $uid);
        if (!$seeCost) unset($r['amount']);
        echo json_encode(['success' => true, 'message' => $approve ? t('Stock given out.') : t('Request rejected.')] + $r);
        return;
    }

    if (!rcpCanCook()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

    if ($action === 'create') {
        $wh = (int)($src['warehouse_id'] ?? 0);
        $shop($wh);
        $lines = json_decode((string)($src['lines'] ?? '[]'), true);
        if (!is_array($lines)) throw new Exception(t('Something went wrong.'));
        $r = rcpCreate($pdo, ['warehouse_id' => $wh, 'product_id' => $src['product_id'] ?? 0, 'pots' => $src['pots'] ?? 0, 'lines' => $lines, 'notes' => $src['notes'] ?? ''], $uid);
        if (!$seeCost) unset($r['recipe_cost'], $r['stock_cost']);
        echo json_encode(['success' => true, 'message' => !empty($r['requested']) ? t('Cooking started. The stock you asked for waits for the storekeeper.') : t('Cooking started.')] + $r);
        return;
    }
    if ($action === 'close') {
        $p = rcpGet($pdo, (int)($src['plan_id'] ?? 0));
        if ($p) $shop((int)$p['warehouse_id']);
        // keep_left=1 (Phase 5): the plates left are kept to sell later — their ingredients stay in stock
        $r = rcpClose($pdo, (int)($src['plan_id'] ?? 0), (float)($src['plates_left'] ?? -1), $uid, (string)($src['keep_left'] ?? '') === '1');
        if (!$seeCost) unset($r['waste_cost']);
        echo json_encode(['success' => true, 'message' => t('Plates left saved.')] + $r);
        return;
    }
    if ($action === 'cancel') {
        $p = rcpGet($pdo, (int)($src['plan_id'] ?? 0));
        if ($p) $shop((int)$p['warehouse_id']);
        rcpCancel($pdo, (int)($src['plan_id'] ?? 0), $uid);
        echo json_encode(['success' => true, 'message' => t('Cooking cancelled.')]);
        return;
    }
    throw new Exception(t('Unknown action.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException || $e instanceof LedgerException) {
        error_log('restaurant cook_plan: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('Database error.')]);
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
