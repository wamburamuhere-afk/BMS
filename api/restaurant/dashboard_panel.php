<?php
/**
 * API: restaurant dashboard panel (restaurant dashboard pass, 2026-11-02).
 *
 * GET part=panel&scope=restaurant|combined          → { success, html }  the panel, for a MIXED company's dashboard
 * GET part=trend&scope=company|restaurant|combined&period=daily|weekly|monthly → { success, trend }  chart data
 *
 * Gate: Restaurant POS + Smart POS on and at least one restaurant/hybrid shop in scope (restaurantModuleOn),
 * plus the same reports access as the retail Performance card — it shows profit.
 * 'company' is only for a restaurant-only company; 'restaurant' / 'combined' only for a mixed one.
 * Read-only: no writes, no ledger posting.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_insights.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!hasReportsAccess() && !canView('invoices') && !canView('sales_report')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!restaurantModuleOn($pdo)) { echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]); exit; }

global $pdo;
$part   = (string)($_GET['part'] ?? 'panel');
$scope  = (string)($_GET['scope'] ?? '');
$only   = restaurantOnlyCompany($pdo);
$allowed = $only ? ['company'] : ['restaurant', 'combined'];
if (!in_array($scope, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}
$ids = rdRestaurantIds($pdo);
if (!$ids) { echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]); exit; }

try {
    if ($part === 'trend') {
        $period = in_array($_GET['period'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_GET['period'] : 'daily';
        $trend = rdTrend($pdo, $period, rdJeScope($ids, $scope === 'combined' ? 'company' : $scope), $scope === 'combined' ? $ids : null);
        echo json_encode(['success' => true, 'trend' => $trend]);
        exit;
    }
    $rdScope = $scope;
    ob_start();
    (function () use ($pdo, $rdScope) { include ROOT_DIR . '/includes/restaurant/dash_panel.php'; })();
    echo json_encode(['success' => true, 'html' => ob_get_clean()]);
} catch (Throwable $e) {
    if (ob_get_level() > 0) ob_end_clean();
    error_log('restaurant dashboard_panel: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
