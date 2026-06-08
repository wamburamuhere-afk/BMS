<?php
/**
 * API: List POS sales (for the POS Sales History page)
 * ----------------------------------------------------------------------------
 * Returns POS sales + return transactions for a date range, project-scoped per
 * §23. Feeds the DataTable / mobile cards on app/bms/pos/sales_history.php.
 *
 * GET: start_date, end_date (optional; default current month)
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';   // loads core/project_scope.php

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');

try {
    global $pdo;
    $scope = scopeFilterSqlNullable('project', 'ps');   // '' for admins; AND (ps.project_id IS NULL OR IN (...)) for non-admins

    // Paid-to-date per sale (guarded: pos_sale_payments arrives with the credit/AR migration).
    $hasPayTable = false;
    try { $hasPayTable = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetch(); } catch (Throwable $e) {}
    $paidExpr = $hasPayTable
        ? "(SELECT COALESCE(SUM(amount),0) FROM pos_sale_payments psp WHERE psp.sale_id = ps.sale_id)"
        : "CASE WHEN ps.payment_status = 'paid' THEN ps.grand_total ELSE 0 END";

    $sql = "SELECT ps.sale_id, ps.receipt_number, ps.sale_date, ps.grand_total, ps.tax_amount,
                   ps.payment_method, ps.payment_status, ps.sale_status, ps.is_return_sale,
                   ps.original_sale_id, ps.void_reason,
                   COALESCE(NULLIF(ps.customer_name,''), c.customer_name, c.company_name, 'Walk-in') AS party,
                   pr.project_name, {$paidExpr} AS amount_paid
              FROM pos_sales ps
         LEFT JOIN customers c ON c.customer_id = ps.customer_id
         LEFT JOIN projects  pr ON pr.project_id = ps.project_id
             WHERE DATE(ps.sale_date) BETWEEN ? AND ?" . $scope . "
          ORDER BY ps.sale_date DESC, ps.sale_id DESC";
    $st = $pdo->prepare($sql);
    $st->execute([$start_date, $end_date]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['sale_id']        = (int)$r['sale_id'];
        $r['grand_total']    = (float)$r['grand_total'];
        $r['tax_amount']     = (float)$r['tax_amount'];
        $r['is_return_sale'] = (int)$r['is_return_sale'];
        $r['amount_paid']    = (float)($r['amount_paid'] ?? 0);
        $r['balance_due']    = round($r['grand_total'] - $r['amount_paid'], 2);
        $r['can_void']       = ($r['is_return_sale'] === 0 && $r['sale_status'] === 'completed');
        $r['can_return']     = ($r['is_return_sale'] === 0 && in_array($r['sale_status'], ['completed', 'partially_refunded'], true));
        $r['can_receive']    = ($r['is_return_sale'] === 0 && $r['sale_status'] !== 'voided' && $r['balance_due'] > 0.01);
    }
    unset($r);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed POS Sales History');
    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('get_sales: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
