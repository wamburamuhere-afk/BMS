<?php
/**
 * API: POS Z-Report for a specific shift
 * ----------------------------------------------------------------------------
 * Returns the full Z-report data for a shift the current user owns.
 * Includes shift header, payment breakdown, top products sold, and
 * a per-method refund summary. The Flutter app uses this to render
 * a printable ESC/POS Z-report.
 *
 * GET: shift_id
 * Permission: canView('pos') + must own the shift (unless admin)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php'; mobileBearerAuth();
require_once __DIR__ . '/../../core/permissions.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

global $pdo;

$shiftId = (int)($_GET['shift_id'] ?? 0);
$userId  = (int)($_SESSION['user_id'] ?? 0);
if ($shiftId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid shift.']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT s.*, u.first_name, u.last_name
        FROM cash_register_shifts s
        LEFT JOIN users u ON u.user_id = s.user_id
        WHERE s.shift_id = ?
    ");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) { echo json_encode(['success' => false, 'message' => 'Shift not found.']); exit; }

    // Non-admins can only view their own shifts
    if (!isAdmin() && (int)$shift['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    // Sale count for this shift
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM pos_sales
        WHERE shift_id = ? AND sale_status != 'voided' AND is_return_sale = 0
    ");
    $cntStmt->execute([$shiftId]);
    $saleCount = (int)$cntStmt->fetchColumn();

    // Return count
    $retStmt = $pdo->prepare("
        SELECT COUNT(*), COALESCE(SUM(grand_total),0)
        FROM pos_sales
        WHERE shift_id = ? AND is_return_sale = 1
    ");
    $retStmt->execute([$shiftId]);
    [$returnCount, $returnTotal] = $retStmt->fetch(PDO::FETCH_NUM);

    // Top products (up to 5)
    $topStmt = $pdo->prepare("
        SELECT p.product_name, SUM(si.quantity) AS total_qty,
               SUM(si.quantity * si.unit_price) AS total_revenue
        FROM pos_sale_items si
        JOIN pos_sales s  ON s.sale_id    = si.sale_id
        JOIN products p   ON p.product_id = si.product_id
        WHERE s.shift_id = ? AND s.sale_status != 'voided' AND s.is_return_sale = 0
        GROUP BY si.product_id
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $topStmt->execute([$shiftId]);
    $topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($topProducts as &$tp) {
        $tp['total_qty']     = (float)$tp['total_qty'];
        $tp['total_revenue'] = (float)$tp['total_revenue'];
    }
    unset($tp);

    // Cash drawer in/out movements (non-sale) during shift
    $drawerStmt = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN transaction_type='cash_in'  THEN amount ELSE 0 END),0) AS cash_in,
          COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END),0) AS cash_out
        FROM cash_register_transactions
        WHERE shift_id = ?
    ");
    $drawerStmt->execute([$shiftId]);
    $drawer = $drawerStmt->fetch(PDO::FETCH_ASSOC) ?? ['cash_in'=>0,'cash_out'=>0];

    $numFields = ['starting_cash','ending_cash','total_sales','total_cash_sales',
                  'total_card_sales','total_mobile_sales','total_credit_sales',
                  'total_refunds','cash_in','cash_out','expected_cash',
                  'actual_cash','cash_difference'];
    foreach ($numFields as $f) {
        $shift[$f] = (float)($shift[$f] ?? 0);
    }
    $shift['shift_id'] = (int)$shift['shift_id'];
    $cashierName = trim(($shift['first_name'] ?? '') . ' ' . ($shift['last_name'] ?? ''));
    unset($shift['first_name'], $shift['last_name']);

    echo json_encode([
        'success'      => true,
        'shift'        => $shift,
        'cashier_name' => $cashierName,
        'sale_count'   => $saleCount,
        'return_count' => (int)$returnCount,
        'return_total' => (float)$returnTotal,
        'top_products' => $topProducts,
        'cash_in'      => (float)$drawer['cash_in'],
        'cash_out'     => (float)$drawer['cash_out'],
    ]);
} catch (Throwable $e) {
    error_log('get_z_report: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
