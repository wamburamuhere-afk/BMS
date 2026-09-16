<?php
// scope-audit: skip — acts on one POS sale by primary key under canView('pos'); warehouse scope enforced below
/**
 * API: One credit sale's full detail — powers the "View" action on the
 * receivables aging page / Madeni tab: sale header + every payment recorded
 * against it (date, amount, method), so a repayment schedule is genuinely
 * visible, not just the current balance.
 *
 * pos_credit_receivables_plan.md Phase 2a/2b.
 *
 * GET: sale_id
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))         { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }

$sale_id = (int)($_GET['sale_id'] ?? 0);
if ($sale_id <= 0) { echo json_encode(['success' => false, 'message' => t('Invalid sale.')]); exit; }

try {
    global $pdo;

    $st = $pdo->prepare("
        SELECT s.sale_id, s.receipt_number, s.customer_id, s.warehouse_id,
               COALESCE(c.customer_name, s.customer_name) AS customer_name,
               COALESCE(c.phone, c.mobile, s.customer_phone) AS customer_phone,
               s.grand_total, s.sale_date, s.due_date, s.payment_status,
               s.sale_status, s.internal_notes
        FROM pos_sales s
        LEFT JOIN customers c ON c.customer_id = s.customer_id
        WHERE s.sale_id = ? AND s.payment_method = 'credit'
    ");
    $st->execute([$sale_id]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale) { echo json_encode(['success' => false, 'message' => t('Credit sale not found.')]); exit; }

    $wid = $sale['warehouse_id'] !== null && $sale['warehouse_id'] !== '' ? (int)$sale['warehouse_id'] : null;
    if ($wid !== null && !userCan('warehouse', $wid)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => isShopLabel(true)
            ? t('Access denied: this shop is not in your assigned scope.')
            : t('Access denied: this warehouse is not in your assigned scope.')]);
        exit;
    }

    $payStmt = $pdo->prepare("
        SELECT payment_id, amount, payment_method, reference, notes, created_at,
               (SELECT username FROM users WHERE user_id = pos_sale_payments.received_by) AS received_by_name
        FROM pos_sale_payments
        WHERE sale_id = ?
        ORDER BY created_at ASC
    ");
    $payStmt->execute([$sale_id]);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $paid = 0.0;
    foreach ($payments as $p) $paid += (float)$p['amount'];
    $balance = round((float)$sale['grand_total'] - $paid, 2);

    echo json_encode([
        'success'  => true,
        'sale'     => $sale,
        'payments' => $payments,
        'paid'     => round($paid, 2),
        'balance_due' => $balance,
    ]);
} catch (Throwable $e) {
    error_log('get_credit_sale_detail: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
