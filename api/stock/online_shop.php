<?php
/**
 * API: a shop's online shop settings and link — Smart POS retail (retail_online_shop_plan.md Phase 3).
 *
 * GET  ?warehouse_id=N                → switches + link (+ whether payment numbers / a wholesale list exist)
 * POST action=save   warehouse_id, enabled, ordering, pickup, delivery, wholesale, show_payment
 * POST action=rotate warehouse_id     → a new link (the old link and any printed QR stop working)
 *
 * Gate: Smart POS on, canEdit('warehouses') (publishing a shop is an owner / manager action),
 * warehouse scope, shop is active and sells retail (retail / hybrid). POST needs CSRF.
 * The link is created the first time the shop is switched on.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/retail_online.php';
require_once __DIR__ . '/../../core/payment_accounts.php';

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; };

if (!isAuthenticated())           $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canEdit('warehouses'))       $out(403, ['success' => false, 'message' => t('Permission denied')]);
if (!posSimpleModeEnabled())      $out(403, ['success' => false, 'message' => t('The online shop is available in Smart POS.')]);
if (!rsSchemaReady($pdo))         $out(200, ['success' => false, 'message' => t('The online shop is being set up for your account — please check back shortly.')]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') csrf_check();
$wid = (int)($method === 'POST' ? ($_POST['warehouse_id'] ?? 0) : ($_GET['warehouse_id'] ?? 0));
if ($wid <= 0 || !userCan('warehouse', $wid)) {
    $out(403, ['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
}
$cur = rsShopSettings($pdo, $wid);
if (!$cur) $out(404, ['success' => false, 'message' => t('Shop not found.')]);
if (!$cur['eligible']) $out(200, ['success' => false, 'message' => t('This shop cannot have an online shop (it is inactive or a restaurant-only shop — use Share menu there).')]);

$extras = function (array $d) use ($pdo): array {
    $d['payment_numbers'] = count(paymentAccountsForBuyer($pdo));
    $d['wholesale_ready'] = wholesalePriceGroupId($pdo) !== null;
    return $d;
};

try {
    if ($method === 'GET') {
        if ($cur['enabled'] && !$cur['link']) { rsEnsureLink($pdo, $wid); $cur = rsShopSettings($pdo, $wid); }
        $out(200, ['success' => true, 'data' => $extras($cur)]);
    }

    $action = (string)($_POST['action'] ?? '');
    $b = fn($k) => !empty($_POST[$k]) && $_POST[$k] !== '0' && $_POST[$k] !== 'false' ? 1 : 0;

    if ($action === 'save') {
        $enabled = $b('enabled'); $ordering = $b('ordering'); $pickup = $b('pickup'); $delivery = $b('delivery');
        // Ordering needs a way to receive the goods: refuse rather than publish a shop nobody can order from.
        if ($enabled && $ordering && !$pickup && !$delivery) {
            $out(422, ['success' => false, 'message' => t('Choose Pickup, Delivery or both before letting customers order.'), 'data' => $extras($cur)]);
        }
        $pdo->prepare("UPDATE warehouses SET online_shop_enabled = ?, online_shop_ordering = ?, online_shop_pickup = ?, online_shop_delivery = ?,
                                             online_shop_wholesale = ?, online_show_payment = ? WHERE warehouse_id = ?")
            ->execute([$enabled, $ordering, $pickup, $delivery, $b('wholesale'), $b('show_payment'), $wid]);
        if ($enabled) rsEnsureLink($pdo, $wid);
        $new = rsShopSettings($pdo, $wid);
        logActivity($pdo, $_SESSION['user_id'], sprintf('Online shop for "%s": %s, ordering %s (pickup %s, delivery %s), wholesale prices %s, payment numbers %s',
            $new['shop'], $new['enabled'] ? 'on' : 'off', $new['ordering'] ? 'on' : 'off', $new['pickup'] ? 'on' : 'off', $new['delivery'] ? 'on' : 'off',
            $new['wholesale'] ? 'shown' : 'hidden', $new['show_payment'] ? 'shown' : 'hidden'));
        logAudit($pdo, $_SESSION['user_id'], 'online_shop_settings', [
            'entity_type' => 'warehouse', 'entity_id' => $wid,
            'old_values'  => array_intersect_key($cur, array_flip(['enabled', 'ordering', 'pickup', 'delivery', 'wholesale', 'show_payment'])),
            'new_values'  => array_intersect_key($new, array_flip(['enabled', 'ordering', 'pickup', 'delivery', 'wholesale', 'show_payment'])),
        ]);
        $out(200, ['success' => true, 'message' => t('Saved.'), 'data' => $extras($new)]);
    }

    if ($action === 'rotate') {
        $pdo->prepare("UPDATE warehouses SET online_shop_token = NULL WHERE warehouse_id = ?")->execute([$wid]);
        rsEnsureLink($pdo, $wid);
        $new = rsShopSettings($pdo, $wid);
        logActivity($pdo, $_SESSION['user_id'], sprintf('Made a new online shop link for "%s" (the old link and printed QR codes stopped working)', $new['shop']));
        logAudit($pdo, $_SESSION['user_id'], 'online_shop_new_link', ['entity_type' => 'warehouse', 'entity_id' => $wid]);
        $out(200, ['success' => true, 'message' => t('New link made. The old link and printed QR codes no longer work.'), 'data' => $extras($new)]);
    }

    $out(400, ['success' => false, 'message' => t('Unknown action.')]);
} catch (Throwable $e) {
    error_log('online_shop: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Database error.')]);
}
