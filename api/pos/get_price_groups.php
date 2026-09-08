<?php
/**
 * api/pos/get_price_groups.php
 *
 * Phase 14 (pos_upgrade_plan.md §8) — list active price groups for the POS
 * terminal's price-group selector and the management page's list view.
 * Read-only; gated by pos_advanced like every other Phase-14 surface.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

if (!canView('pos_advanced')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Price groups are not included in your plan.')]);
    exit;
}

global $pdo;

try {
    $rows = $pdo->query("
        SELECT price_group_id, name, is_default
        FROM price_groups
        WHERE status = 'active'
        ORDER BY is_default DESC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['price_group_id'] = (int)$r['price_group_id'];
        $r['is_default'] = (bool)$r['is_default'];
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('get_price_groups error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
