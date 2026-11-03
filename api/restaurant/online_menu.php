<?php
/**
 * API: a restaurant shop's online menu settings and links
 * (restaurant_online_menu_plan.md Phase 4).
 *
 * GET  ?warehouse_id=N                 → switches + menu link + one link per table
 * POST action=save   warehouse_id, enabled, order_table, order_pickup
 * POST action=rotate warehouse_id      → new links (every old link / printed QR stops working)
 *
 * Gate: Restaurant module on, canEdit('restaurant_pos') (owner/manager — it
 * publishes the menu), warehouse scope, shop is Restaurant/Hybrid. POST needs CSRF.
 * Links are created the first time the menu is switched on, and a table added
 * later gets its QR the next time this is read.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) loadLanguage($_SESSION['user_lang']);
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/restaurant_online.php';

$out = function (int $code, array $b) { http_response_code($code); echo json_encode($b); exit; };

if (!isAuthenticated())               $out(401, ['success' => false, 'message' => t('Unauthorized')]);
if (!canView('restaurant_pos'))       $out(403, ['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]);
if (!canEdit('restaurant_pos'))       $out(403, ['success' => false, 'message' => t('Permission denied')]);
if (!restaurantSchemaReady($pdo) || !restaurantModuleOn($pdo) || !roSchemaReady($pdo)) $out(200, ['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') csrf_check();
$wid = (int)($method === 'POST' ? ($_POST['warehouse_id'] ?? 0) : ($_GET['warehouse_id'] ?? 0));
if ($wid <= 0 || !userCan('warehouse', $wid)) {
    $out(403, ['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
}
if (!restaurantShopActive($pdo, $wid)) $out(200, ['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);

$ensureLinks = fn() => roEnsureLinks($pdo, $wid);
$read        = fn() => roShopLinks($pdo, $wid);

try {
    if ($method === 'GET') {
        $cur = $read();
        if ($cur['enabled']) { $ensureLinks(); $cur = $read(); }
        $out(200, ['success' => true, 'data' => $cur]);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $b = fn($k) => !empty($_POST[$k]) && $_POST[$k] !== '0' && $_POST[$k] !== 'false' ? 1 : 0;
        $enabled = $b('enabled');
        $pdo->prepare("UPDATE warehouses SET online_menu_enabled = ?, online_order_table = ?, online_order_pickup = ? WHERE warehouse_id = ?")
            ->execute([$enabled, $b('order_table'), $b('order_pickup'), $wid]);
        if ($enabled) $ensureLinks();
        $cur = $read();
        logActivity($pdo, $_SESSION['user_id'], sprintf('Online menu for "%s": %s, table ordering %s, pickup ordering %s',
            $cur['shop'], $enabled ? 'on' : 'off', $cur['order_table'] ? 'on' : 'off', $cur['order_pickup'] ? 'on' : 'off'));
        $out(200, ['success' => true, 'message' => t('Saved.'), 'data' => $cur]);
    }

    if ($action === 'rotate') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE warehouses SET online_menu_token = NULL WHERE warehouse_id = ?")->execute([$wid]);
        $pdo->prepare("UPDATE restaurant_tables SET qr_token = NULL WHERE warehouse_id = ?")->execute([$wid]);
        $ensureLinks();
        $pdo->commit();
        $cur = $read();
        logActivity($pdo, $_SESSION['user_id'], sprintf('Made new online menu links for "%s" (old links and printed QR codes stopped working)', $cur['shop']));
        $out(200, ['success' => true, 'message' => t('New links made. Old links and printed QR codes no longer work.'), 'data' => $cur]);
    }

    $out(400, ['success' => false, 'message' => t('Unknown action.')]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('online_menu: ' . $e->getMessage());
    $out(500, ['success' => false, 'message' => t('Database error.')]);
}
