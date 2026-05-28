<?php
/**
 * Cash Flow Statement — Data API
 *
 * Period-based (start_date → end_date) report of actual cash IN and OUT
 * grouped into IAS 7 categories. Direct method, reading from operational
 * tables (the canonical cash-event triggers in BMS).
 *
 * Per project guidance: LOANS are excluded entirely. The Financing
 * section therefore stays empty (no company-borrowing tracking exists
 * yet, and dividends/share-capital tracking is also absent).
 *
 * OPERATING ACTIVITIES (direct method)
 *   Cash from customers   = SUM(payments.amount)              [cash IN]
 *   Cash to suppliers     = SUM(supplier_payments.amount)     [cash OUT]
 *   Salaries paid         = SUM(payroll.net_salary) WHERE     [cash OUT]
 *                           payment_status='paid'
 *   Other operating
 *      expenses paid      = SUM(expenses.amount) WHERE        [cash OUT]
 *                           status='paid' (project + general)
 *
 * INVESTING ACTIVITIES
 *   Asset purchases       = SUM(assets.cost) WHERE             [cash OUT]
 *                           purchase_date in window
 *
 * FINANCING ACTIVITIES (none today — placeholder line stays at 0)
 *
 * Opening cash balance (informational) is read from accounts.current_balance
 * for bank/cash-type asset accounts AT TIME OF QUERY — there's no
 * historical opening-balance snapshotting yet, so the "opening" figure
 * is approximate. Closing = Opening + Net Change.
 *
 * Project filter + user scope: same shape as Balance Sheet / Income
 * Statement. Cash totals are company-wide so when a specific project is
 * selected, only project-tagged invoice payments / supplier payments /
 * expenses count; opening/closing-cash and asset purchases drop to 0 with
 * a banner.
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta: { current_start, current_end, project_id, project_filter_active,
 *               is_admin, scoped_project_ids, opening_cash, closing_cash },
 *       sections: {
 *           operating: { lines, total }
 *           investing: { lines, total }
 *           financing: { lines, total }
 *       },
 *       totals: { net_change_in_cash }
 *   } }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

$is_admin = isAdmin();
$user_project_ids = [];
if (!$is_admin) {
    $user_project_ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: this project is not in your assigned scope.',
    ]);
    exit;
}

try {
    global $pdo;

    $scopeClause = function (string $col, string $alias = '') use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        }
        return ['sql' => scopeFilterSqlNullable('project', $alias), 'params' => []];
    };

    // ─── OPERATING ACTIVITIES ───────────────────────────────────────────

    // Cash from customers — payments.amount in window.
    // payments routes through invoices for project filter.
    $scope = $scopeClause('i.project_id', 'i');
    $sql = "SELECT COALESCE(SUM(p.amount), 0)
              FROM payments p
         LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
             WHERE p.payment_date BETWEEN ? AND ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$start_date, $end_date], $scope['params']));
    $cash_from_customers = (float)$stmt->fetchColumn();

    // Cash to suppliers — supplier_payments.amount in window.
    // supplier_payments may have its own project_id or resolve via supplier_invoice.
    // Test column existence to avoid surprises.
    $cash_to_suppliers = 0.0;
    try {
        $spCols = $pdo->query("SHOW COLUMNS FROM supplier_payments")->fetchAll(PDO::FETCH_COLUMN);
        $hasProjectId = in_array('project_id', $spCols, true);
        $dateCol = in_array('payment_date', $spCols, true)
            ? 'payment_date'
            : (in_array('date', $spCols, true) ? 'date' : 'created_at');
        if ($hasProjectId) {
            $scope = $scopeClause('project_id', '');
            $sql = "SELECT COALESCE(SUM(amount), 0)
                      FROM supplier_payments
                     WHERE $dateCol BETWEEN ? AND ?"
                 . $scope['sql'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$start_date, $end_date], $scope['params']));
        } else {
            // Without project_id: when a specific project is selected, we
            // can't attribute, so degrade to 0 to avoid over-attribution.
            if ($project_id === null) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE $dateCol BETWEEN ? AND ?");
                $stmt->execute([$start_date, $end_date]);
            } else {
                $stmt = null;
            }
        }
        if ($stmt) $cash_to_suppliers = (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cash_to_suppliers = 0.0;
    }

    // Salaries paid — payroll.net_salary where payment_status='paid'.
    // No project_id on payroll → hidden under specific-project view.
    $salaries_paid = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(net_salary), 0)
              FROM payroll
             WHERE payment_status = 'paid'
               AND payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $salaries_paid = (float)$stmt->fetchColumn();
    }

    // Other operating expenses paid — expenses.status='paid' in window.
    // Includes both project-direct and general operating per the Path B
    // split; together they represent cash that went out for opex.
    $scope = $scopeClause('project_id', '');
    $sql = "SELECT COALESCE(SUM(amount), 0)
              FROM expenses
             WHERE status = 'paid'
               AND payroll_id IS NULL
               AND expense_date BETWEEN ? AND ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$start_date, $end_date], $scope['params']));
    $other_opex_paid = (float)$stmt->fetchColumn();

    // Operating lines: cash in is positive; cash out is negative.
    $operating_lines = [];
    if (abs($cash_from_customers) > 0.001) $operating_lines[] = ['name' => 'Cash from customers',         'amount' =>  $cash_from_customers];
    if (abs($cash_to_suppliers)   > 0.001) $operating_lines[] = ['name' => 'Cash paid to suppliers',     'amount' => -$cash_to_suppliers];
    if (abs($salaries_paid)       > 0.001) $operating_lines[] = ['name' => 'Salaries paid',              'amount' => -$salaries_paid];
    if (abs($other_opex_paid)     > 0.001) $operating_lines[] = ['name' => 'Other operating expenses',   'amount' => -$other_opex_paid];

    $net_operating = $cash_from_customers - $cash_to_suppliers - $salaries_paid - $other_opex_paid;

    // ─── INVESTING ACTIVITIES ───────────────────────────────────────────
    $asset_purchases = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cost), 0)
              FROM assets
             WHERE purchase_date BETWEEN ? AND ?
        ");
        $stmt->execute([$start_date, $end_date]);
        $asset_purchases = (float)$stmt->fetchColumn();
    }

    $investing_lines = [];
    if (abs($asset_purchases) > 0.001) {
        $investing_lines[] = ['name' => 'Purchase of fixed assets', 'amount' => -$asset_purchases];
    }
    $net_investing = -$asset_purchases;

    // ─── FINANCING ACTIVITIES ───────────────────────────────────────────
    // Per user instruction, loans are out of scope; no equity / dividends
    // tracking either. Section stays empty.
    $financing_lines = [];
    $net_financing  = 0.0;

    // ─── Opening / Closing cash (informational; not historical) ─────────
    $opening_cash = 0.0;
    $closing_cash = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(current_balance), 0)
              FROM accounts
             WHERE account_type_id = 1
               AND status = 'active'
               AND (account_name LIKE '%bank%'
                    OR account_name LIKE '%cash%'
                    OR account_name LIKE '%CRDB%'
                    OR account_name LIKE '%NMB%'
                    OR account_name LIKE '%mpesa%'
                    OR account_name LIKE '%mobile money%')
        ");
        $closing_cash = (float)$stmt->fetchColumn();
        $opening_cash = $closing_cash - ($net_operating + $net_investing + $net_financing);
    }

    $net_change_in_cash = $net_operating + $net_investing + $net_financing;

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'         => $start_date,
                'current_end'           => $end_date,
                'project_id'            => $project_id,
                'project_filter_active' => $project_id !== null,
                'is_admin'              => $is_admin,
                'scoped_project_ids'    => $is_admin ? null : $user_project_ids,
                'opening_cash'          => $opening_cash,
                'closing_cash'          => $closing_cash,
            ],
            'sections' => [
                'operating' => ['lines' => $operating_lines, 'total' => $net_operating],
                'investing' => ['lines' => $investing_lines, 'total' => $net_investing],
                'financing' => ['lines' => $financing_lines, 'total' => $net_financing],
            ],
            'totals' => [
                'net_change_in_cash' => $net_change_in_cash,
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Cash Flow API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
