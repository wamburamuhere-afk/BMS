<?php
// scope-audit: skip — reads pos_sales via userCan('warehouse', ...) per-row below; no bare SQL scope needed (dataset is small, filtered in PHP)
/**
 * API: POS credit-receivables aging list
 * ----------------------------------------------------------------------------
 * pos_credit_receivables_plan.md Phase 2a/2b — feeds both the dedicated "Who
 * Owes Me" page and, filtered to one customer_id, the customer detail page's
 * "Madeni" tab. Simple POS only, same as every other new surface in this
 * feature — a request from a tenant not running Simple POS gets a clear
 * error rather than silently returning real data through a URL nobody
 * should be linking to anyway.
 *
 * GET: customer_id? (optional — filters to one customer)
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';
require_once __DIR__ . '/../../core/pos_credit_limit.php';
require_once __DIR__ . '/../../core/pos_credit_aging.php';

header('Content-Type: application/json');

if (!isAuthenticated())        { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))           { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled())   { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }

try {
    global $pdo;
    $customerId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

    $rows = posCreditOpenSales($pdo, $customerId);

    // Row-level warehouse scope — a non-admin only sees credit sales from a
    // warehouse they're actually granted, same discipline as every other POS
    // list (receive_payment.php/void_sale.php already gate the ACTION the
    // same way; this gates what's even listed). No extra query needed — the
    // helper already carries warehouse_id per row.
    if (!hasAllWarehouseAccess()) {
        $rows = array_values(array_filter($rows, function ($r) {
            $wid = $r['warehouse_id'];
            return $wid === null || $wid === '' || userCan('warehouse', (int)$wid);
        }));
    }

    $total = 0.0;
    foreach ($rows as $r) $total += $r['balance_due'];

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'total_outstanding' => round($total, 2),
        'count'   => count($rows),
    ]);
} catch (Throwable $e) {
    error_log('get_credit_aging: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
