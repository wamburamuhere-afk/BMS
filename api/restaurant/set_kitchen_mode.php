<?php
/**
 * API: turn the kitchen screen on (restaurant simplicity pass).
 *
 * POST: (nothing) → sets restaurant_kitchen_mode = 'screen'
 *
 * The Kitchen Display page offers this one-click switch when orders are currently
 * "by mouth" (the default), so an owner does not have to dig through Selling Settings.
 * It only ever switches the screen ON — choosing a printer, or going back to "by mouth",
 * stays in Selling Settings. Same permission as that page ('pos_config_settings').
 *
 * Gate: Restaurant POS + Smart POS on (restaurantModuleOn) + canEdit('pos_config_settings').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
if (!restaurantModuleOn($pdo)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}

if (save_setting('restaurant_kitchen_mode', 'screen') === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
    exit;
}
logActivity($pdo, (int)$_SESSION['user_id'], 'Restaurant: kitchen screen turned on');
echo json_encode(['success' => true, 'message' => t('Kitchen screen is on.')]);
