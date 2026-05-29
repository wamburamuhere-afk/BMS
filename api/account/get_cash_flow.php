<?php
/**
 * Cash Flow Statement — Data API (Phase 3.1)
 *
 * Period-based (start_date → end_date) report of actual cash IN and OUT
 * grouped into IAS 7 / IFRS for SMEs §7 categories. Direct method,
 * reading from operational tables (the canonical cash-event triggers
 * in BMS). Phase 3.1 adds the comparative period column required by
 * IFRS for SMEs paragraph 2.34.
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
 * Opening / closing cash is INFORMATIONAL — read from
 * accounts.current_balance for bank/cash-type asset accounts AT TIME OF
 * QUERY. There's no historical snapshotting yet, so:
 *   - current-period closing  = today's bank balance (approximation)
 *   - current-period opening  = closing − net change in current window
 *   - comparative closing     = today's bank balance − current-period
 *                                net change (= what the balance was at
 *                                the end of the comparative window)
 *   - comparative opening     = comparative closing − comparative net
 *                                change
 * This is approximate but produces consistent figures for the
 * comparative column. Phase 5 will replace this with proper historical
 * snapshots when canonical-ledger postings exist.
 *
 * Project filter + user scope: same shape as Balance Sheet / Income
 * Statement. Cash totals are company-wide so when a specific project is
 * selected, only project-tagged invoice payments / supplier payments /
 * expenses count; opening/closing-cash and asset purchases drop to 0.
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta: { current_start, current_end,
 *               comparative_start, comparative_end,
 *               project_id, project_filter_active,
 *               is_admin, scoped_project_ids,
 *               opening_cash, closing_cash,
 *               comparative_opening_cash, comparative_closing_cash },
 *       sections: {
 *           operating: { lines: [{name, amount, comparative_amount}], total, comparative_total }
 *           investing: { lines: [{name, amount, comparative_amount}], total, comparative_total }
 *           financing: { lines: [{name, amount, comparative_amount}], total, comparative_total }
 *       },
 *       totals: { net_change_in_cash, comparative: { net_change_in_cash } }
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

// IFRS for SMEs §2.34 — comparative is the same calendar period one year prior.
$comparative_start = date('Y-m-d', strtotime("$start_date -1 year"));
$comparative_end   = date('Y-m-d', strtotime("$end_date -1 year"));

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

    // ───────────────────────────────────────────────────────────────────────
    // Per-window computation closure. Called twice: once for current window,
    // once for comparative window. Returns:
    //   ['cash_from_customers', 'cash_to_suppliers', 'salaries_paid',
    //    'other_opex_paid', 'asset_purchases', 'net_operating',
    //    'net_investing', 'net_financing', 'net_change_in_cash']
    // ───────────────────────────────────────────────────────────────────────
    $computeWindow = function (string $from, string $to) use ($pdo, $scopeClause, $project_id): array {

        // Cash from customers — payments.amount in window. payments route
        // through invoices for project filter.
        $scope = $scopeClause('i.project_id', 'i');
        $sql = "SELECT COALESCE(SUM(p.amount), 0)
                  FROM payments p
             LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
                 WHERE p.payment_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        $cash_from_customers = (float)$stmt->fetchColumn();

        // Cash to suppliers — supplier_payments.amount in window.
        // Detect available columns defensively (some schemas vary).
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
                $stmt->execute(array_merge([$from, $to], $scope['params']));
            } else {
                if ($project_id === null) {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE $dateCol BETWEEN ? AND ?");
                    $stmt->execute([$from, $to]);
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
            $stmt->execute([$from, $to]);
            $salaries_paid = (float)$stmt->fetchColumn();
        }

        // Other operating expenses paid — expenses.status='paid' in window.
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM expenses
                 WHERE status = 'paid'
                   AND payroll_id IS NULL
                   AND expense_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        $other_opex_paid = (float)$stmt->fetchColumn();

        // Asset purchases — assets.cost in window.
        $asset_purchases = 0.0;
        if ($project_id === null) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(cost), 0)
                  FROM assets
                 WHERE purchase_date BETWEEN ? AND ?
            ");
            $stmt->execute([$from, $to]);
            $asset_purchases = (float)$stmt->fetchColumn();
        }

        // Section totals
        $net_operating = $cash_from_customers - $cash_to_suppliers - $salaries_paid - $other_opex_paid;
        $net_investing = -$asset_purchases;
        $net_financing = 0.0;
        $net_change_in_cash = $net_operating + $net_investing + $net_financing;

        return [
            'cash_from_customers'  => $cash_from_customers,
            'cash_to_suppliers'    => $cash_to_suppliers,
            'salaries_paid'        => $salaries_paid,
            'other_opex_paid'      => $other_opex_paid,
            'asset_purchases'      => $asset_purchases,
            'net_operating'        => $net_operating,
            'net_investing'        => $net_investing,
            'net_financing'        => $net_financing,
            'net_change_in_cash'   => $net_change_in_cash,
        ];
    };

    // Run for both windows.
    $cur = $computeWindow($start_date, $end_date);
    $cmp = $computeWindow($comparative_start, $comparative_end);

    // ─── Build line lists with comparative amounts ────────────────────────
    // Helper: when EITHER current or comparative amount is non-zero, the line
    // appears. Zero-zero lines are hidden to keep the report clean.
    $buildLine = function (string $name, float $cur_amt, float $cmp_amt): ?array {
        if (abs($cur_amt) < 0.001 && abs($cmp_amt) < 0.001) return null;
        return ['name' => $name, 'amount' => $cur_amt, 'comparative_amount' => $cmp_amt];
    };

    $operating_lines = array_values(array_filter([
        $buildLine('Cash from customers',         $cur['cash_from_customers'],  $cmp['cash_from_customers']),
        $buildLine('Cash paid to suppliers',     -$cur['cash_to_suppliers'],   -$cmp['cash_to_suppliers']),
        $buildLine('Salaries paid',              -$cur['salaries_paid'],       -$cmp['salaries_paid']),
        $buildLine('Other operating expenses',   -$cur['other_opex_paid'],     -$cmp['other_opex_paid']),
    ]));

    $investing_lines = array_values(array_filter([
        $buildLine('Purchase of fixed assets',   -$cur['asset_purchases'],     -$cmp['asset_purchases']),
    ]));

    $financing_lines = [];   // empty by design

    // ─── Opening / Closing cash ──────────────────────────────────────────
    // Current closing  = today's bank balance.
    // Current opening  = closing − current net change.
    // Comparative closing = closing − current net change
    //                     = opening at start of current period
    //                     = ALSO the closing at end of comparative period
    //                       (one year prior).
    // Comparative opening = comparative closing − comparative net change.
    $opening_cash = 0.0;
    $closing_cash = 0.0;
    $cmp_opening_cash = 0.0;
    $cmp_closing_cash = 0.0;
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
        $opening_cash = $closing_cash - $cur['net_change_in_cash'];
        $cmp_closing_cash = $opening_cash;
        $cmp_opening_cash = $cmp_closing_cash - $cmp['net_change_in_cash'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'             => $start_date,
                'current_end'               => $end_date,
                'comparative_start'         => $comparative_start,
                'comparative_end'           => $comparative_end,
                'project_id'                => $project_id,
                'project_filter_active'     => $project_id !== null,
                'is_admin'                  => $is_admin,
                'scoped_project_ids'        => $is_admin ? null : $user_project_ids,
                'opening_cash'              => $opening_cash,
                'closing_cash'              => $closing_cash,
                'comparative_opening_cash'  => $cmp_opening_cash,
                'comparative_closing_cash'  => $cmp_closing_cash,
            ],
            'sections' => [
                'operating' => [
                    'lines'             => $operating_lines,
                    'total'             => $cur['net_operating'],
                    'comparative_total' => $cmp['net_operating'],
                ],
                'investing' => [
                    'lines'             => $investing_lines,
                    'total'             => $cur['net_investing'],
                    'comparative_total' => $cmp['net_investing'],
                ],
                'financing' => [
                    'lines'             => $financing_lines,
                    'total'             => $cur['net_financing'],
                    'comparative_total' => $cmp['net_financing'],
                ],
            ],
            'totals' => [
                'net_change_in_cash' => $cur['net_change_in_cash'],
                'comparative' => [
                    'net_change_in_cash' => $cmp['net_change_in_cash'],
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Cash Flow API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
