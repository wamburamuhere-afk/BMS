<?php
// scope-audit: skip — the warehouse is checked with userCan('warehouse', …) below; shifts are the caller's own
/**
 * API: Record Old Sale — preview for the POS pop-up
 * ----------------------------------------------------------------------------
 * Read-only. Tells the cashier, before anything is saved, whether a time can be
 * used and which shift an old sale at that time would land in (their closed
 * shift, the shift still open, their back-dated shift for that day, or a new
 * back-dated one). With a total, also lists sales already recorded that day for
 * the same amount — a likely double entry. Rules: core/pos_old_sales.php.
 *
 * GET: sold_at ('Y-m-d H:i'), warehouse_id (optional), total (optional)
 * Permission: canView('pos_past_sales')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php'; mobileBearerAuth();
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_old_sales.php';

header('Content-Type: application/json');

if (!isAuthenticated())          { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_past_sales'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('You do not have permission to record old sales.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }

$soldAt      = trim((string)($_GET['sold_at'] ?? ''));
$warehouseId = (int)($_GET['warehouse_id'] ?? 0) ?: null;
$total       = (float)($_GET['total'] ?? 0);

if ($warehouseId !== null && !userCan('warehouse', $warehouseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.', true)]);
    exit;
}

try {
    global $pdo;
    $out = ['success' => true, 'max_days' => posOldSaleMaxDays(), 'error' => posOldSaleTimeError($soldAt)];
    if ($out['error'] === null) {
        $at   = date('Y-m-d H:i:s', strtotime($soldAt));
        $p    = posOldSaleShiftPreview($pdo, (int)$_SESSION['user_id'], $at);
        $day  = date('d/m/Y', strtotime($at));
        switch ($p['kind']) {
            case 'active':    $label = sprintf(t('Goes into your shift that is still open (%s). Your current cash balance will go up.'), $p['shift_code']); break;
            case 'closed':    $label = sprintf(t('Goes into your closed shift of %s (%s). Its Z-report is updated.'), $day, $p['shift_code']); break;
            case 'backdated': $label = sprintf(t('Goes into your back-dated shift of %s (%s).'), $day, $p['shift_code']); break;
            default:          $label = sprintf(t('You had no shift at that time on %s. A closed back-dated shift will be created.'), $day);
        }
        $out['shift'] = $p + ['label' => $label];
        $out['similar'] = array_map(function ($s) {
            return ['receipt_number' => $s['receipt_number'], 'time' => date('H:i', strtotime($s['sale_date'])), 'total' => round((float)$s['grand_total'], 2)];
        }, posOldSaleSimilar($pdo, substr($at, 0, 10), $total, $warehouseId));
    }
    echo json_encode($out);
} catch (Throwable $e) {
    error_log('old_sale_context: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error. Please try again.')]);
}
