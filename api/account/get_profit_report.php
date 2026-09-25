<?php
/**
 * api/account/get_profit_report.php
 *
 * AJAX data source for the Profit Report (Simple POS only) — gross/net
 * profit for a chosen date range, plus a monthly trend. Single source of
 * truth per .claude/reporting-source.md: glProfitLoss() in
 * core/financial_reports.php (posted journal_entries only). No raw POS/
 * expense SQL here — this endpoint is a thin UI wrapper around that engine.
 *
 * Project/warehouse-scoped per .claude/security.md §23.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';
require_once __DIR__ . '/../../core/financial_classification.php';
require_once __DIR__ . '/../../core/financial_reports.php';

global $pdo;

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    return;
}
if (!canView('pos') || !canView('pos_profit_report')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    return;
}
if (!posSimpleModeEnabled()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not available']);
    return;
}

if (!fc_classification_ready($pdo)) {
    echo json_encode(['success' => false, 'message' =>
        'Profit Report is not available: the account-type classification has not been installed on this server.']);
    return;
}

$date_from    = $_GET['date_from'] ?? date('Y-01-01');
$date_to      = $_GET['date_to']   ?? date('Y-m-d');
$project_id   = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;
if (!projectsModuleActive()) $project_id = null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    return;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    return;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => isShopLabel() ? 'Access denied: this shop is not in your assigned scope.' : 'Access denied: this warehouse is not in your assigned scope.']);
    return;
}

try {
    global $pdo;

    $scopeSql = ($project_id === null ? scopeFilterSqlNullable('project', 'je') : '')
              . ($warehouse_id === null ? scopeFilterSqlNullable('warehouse', 'je') : '');

    $result = glProfitLoss($pdo, $date_from, $date_to, $project_id, $scopeSql, $warehouse_id);

    $netMargin = $result['total_revenue'] > 0.001
        ? round(($result['net_profit'] / $result['total_revenue']) * 100, 1)
        : 0.0;

    // ── Monthly trend: one glProfitLoss() call per calendar month in range,
    //    capped at 24 points — same engine, never re-derived SQL.
    $trend = [];
    $cursor = new DateTime(date('Y-m-01', strtotime($date_from)));
    $end    = new DateTime($date_to);
    $months = 0;
    while ($cursor <= $end && $months < 24) {
        $mStart = $cursor->format('Y-m-d');
        $mEndDt = (clone $cursor)->modify('last day of this month');
        $mEnd   = $mEndDt > $end ? $end->format('Y-m-d') : $mEndDt->format('Y-m-d');
        if ($mStart > $mEnd) break;

        $m = glProfitLoss($pdo, $mStart, $mEnd, $project_id, $scopeSql, $warehouse_id);
        $trend[] = [
            'label'       => $cursor->format('Y-m'),
            'revenue'     => $m['total_revenue'],
            'cogs'        => $m['total_cogs'],
            'net_profit'  => $m['net_profit'],
        ];

        $cursor->modify('first day of next month');
        $months++;
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_revenue' => $result['total_revenue'],
            'total_cogs'    => $result['total_cogs'],
            'gross_profit'  => $result['gross_profit'],
            'total_expense' => $result['total_expense'],
            'net_profit'    => $result['net_profit'],
            'net_margin_pct'=> $netMargin,
        ],
        'trend' => $trend,
    ]);

} catch (Throwable $e) {
    error_log('get_profit_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
