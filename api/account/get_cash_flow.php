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

// Phase 3.2 — method selector: 'direct' (default) | 'indirect'
// Both methods produce the same operating-total in a balanced ledger.
// The investing + financing sections are method-independent.
$method = ($_GET['method'] ?? 'direct') === 'indirect' ? 'indirect' : 'direct';

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

    // ───────────────────────────────────────────────────────────────────────
    // Phase 3.2 — INDIRECT-METHOD operating section computation.
    //
    // Required by IFRS for SMEs §7. Starts from Profit Before Tax and adjusts
    // for non-cash items + working-capital changes to derive operating cash
    // flow. Reuses Income Statement API for profit, Balance Sheet API
    // (via direct queries with identical logic) for working capital deltas.
    //
    // Both methods produce the SAME operating total in a fully-balanced
    // ledger. Any difference is the reconciliation gap and is exposed in
    // meta.operating_reconciliation_difference so accountants can see it.
    //
    // Per accountant preference (your Phase 3.2 pick "a"): always show
    // every line including zero ones — explicit acknowledgement that the
    // line exists but isn't yet populated (e.g. Depreciation = 0 until
    // Phase 2 of the assets module begins posting depreciation runs).
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Internal helper: fetch a peer API's JSON response by swapping $_GET,
     * capturing stdout, then restoring. Used to call IS API for profit.
     * The API files don't call exit() on success — they end with
     * `echo json_encode(...)` and `require` returns naturally.
     */
    $fetchPeer = function (string $api_path, array $params): ?array {
        $saved = $_GET;
        $_GET = $params;
        $prev = error_reporting(error_reporting() & ~E_WARNING);
        ob_start();
        try { require $api_path; } catch (Throwable $e) {}
        $raw = ob_get_clean();
        error_reporting($prev);
        $_GET = $saved;
        $d = json_decode($raw, true);
        return ($d && !empty($d['success'])) ? $d['data'] : null;
    };

    /**
     * Working-capital snapshots at a point in time. Same logic as
     * get_balance_sheet.php (Trade Receivables, Inventory, Trade Payables,
     * Tax Payable). Extracted here to avoid the BS API's response-shape
     * dependency. Project-scope respected via $scopeClause.
     */
    $wcSnapshot = function (string $as_of) use ($pdo, $scopeClause, $project_id): array {
        $scope = $scopeClause('project_id', '');

        // Trade Receivables — unpaid invoices' balance_due as of date.
        $sql = "SELECT COALESCE(SUM(balance_due), 0)
                  FROM invoices
                 WHERE status NOT IN ('paid','cancelled')
                   AND invoice_date <= ?"
             . $scope['sql'];
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$as_of], $scope['params']));
        $ar = (float)$st->fetchColumn();

        // Inventory — company-wide only.
        $inv = 0.0;
        if ($project_id === null) {
            $st = $pdo->query("
                SELECT COALESCE(SUM(ps.stock_quantity * COALESCE(p.cost_price, 0)), 0)
                  FROM product_stocks ps
             LEFT JOIN products p ON p.product_id = ps.product_id
                 WHERE ps.stock_quantity > 0
            ");
            $inv = (float)$st->fetchColumn();
        }

        // Trade Payables — supplier invoices approved + unpaid.
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM supplier_invoices
                 WHERE status = 'approved'
                   AND payment_date IS NULL
                   AND date_recorded <= ?"
             . $scope['sql'];
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$as_of], $scope['params']));
        $ap = (float)$st->fetchColumn();

        // Tax Payable — proportional VAT on unpaid invoices.
        $sql = "SELECT COALESCE(SUM(tax_amount * (balance_due / NULLIF(grand_total,0))), 0)
                  FROM invoices
                 WHERE status NOT IN ('paid','cancelled')
                   AND invoice_date <= ?
                   AND balance_due > 0"
             . $scope['sql'];
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$as_of], $scope['params']));
        $tax = (float)$st->fetchColumn();

        return ['ar' => $ar, 'inventory' => $inv, 'ap' => $ap, 'tax_payable' => $tax];
    };

    /**
     * Compute indirect-method Operating section for a window:
     *   profit_before_tax
     *   + depreciation (currently 0 — Phase 2 of assets will populate)
     *   − Δ Trade Receivables  (Δ = end − start)
     *   − Δ Inventory
     *   + Δ Trade Payables
     *   + Δ Tax Payable
     *   = Net cash from operating
     */
    $computeIndirectOperating = function (string $from, string $to) use ($fetchPeer, $wcSnapshot, $project_id, $start_date, $end_date) {
        // 1. Profit before tax — call IS API for this window
        $isParams = ['start_date' => $from, 'end_date' => $to];
        if ($project_id !== null) $isParams['project_id'] = (string)$project_id;
        $isData = $fetchPeer(__DIR__ . '/get_income_statement.php', $isParams);
        $pbt = ($isData && isset($isData['totals']['profit_before_tax']))
            ? (float)$isData['totals']['profit_before_tax']
            : 0.0;

        // 2. Working-capital snapshots at start and end of window
        // (note: "start" is the END of the day BEFORE the window opens,
        // i.e. one day before $from)
        $wc_start = $wcSnapshot(date('Y-m-d', strtotime("$from -1 day")));
        $wc_end   = $wcSnapshot($to);

        $d_ar  = $wc_end['ar']          - $wc_start['ar'];
        $d_inv = $wc_end['inventory']   - $wc_start['inventory'];
        $d_ap  = $wc_end['ap']          - $wc_start['ap'];
        $d_tax = $wc_end['tax_payable'] - $wc_start['tax_payable'];

        // 3. Depreciation — currently 0 (Phase 2 of assets will fill).
        $depreciation = 0.0;

        // Indirect operating total
        $net_op = $pbt + $depreciation - $d_ar - $d_inv + $d_ap + $d_tax;

        return [
            'profit_before_tax'    => $pbt,
            'depreciation'         => $depreciation,
            'delta_ar'             => $d_ar,
            'delta_inventory'      => $d_inv,
            'delta_ap'             => $d_ap,
            'delta_tax_payable'    => $d_tax,
            'net_operating'        => $net_op,
        ];
    };

    if ($method === 'indirect') {
        $cur_ind = $computeIndirectOperating($start_date, $end_date);
        $cmp_ind = $computeIndirectOperating($comparative_start, $comparative_end);

        // Build indirect operating lines. Per accountant preference, show
        // every line including zeros — keep the reconciliation explicit.
        $operating_lines = [
            [
                'name'               => 'Profit before tax',
                'amount'             => $cur_ind['profit_before_tax'],
                'comparative_amount' => $cmp_ind['profit_before_tax'],
            ],
            ['name' => 'Adjustments for:', 'amount' => null, 'comparative_amount' => null, 'is_subheader' => true],
            [
                'name'               => '  Depreciation',
                'amount'             => $cur_ind['depreciation'],
                'comparative_amount' => $cmp_ind['depreciation'],
                'note'               => 'Depreciation engine not yet posting (Phase 2 of assets module)',
            ],
            ['name' => 'Changes in working capital:', 'amount' => null, 'comparative_amount' => null, 'is_subheader' => true],
            [
                'name'               => '  (Increase)/decrease in Trade Receivables',
                'amount'             => -$cur_ind['delta_ar'],
                'comparative_amount' => -$cmp_ind['delta_ar'],
            ],
            [
                'name'               => '  (Increase)/decrease in Inventory',
                'amount'             => -$cur_ind['delta_inventory'],
                'comparative_amount' => -$cmp_ind['delta_inventory'],
            ],
            [
                'name'               => '  Increase/(decrease) in Trade Payables',
                'amount'             =>  $cur_ind['delta_ap'],
                'comparative_amount' =>  $cmp_ind['delta_ap'],
            ],
            [
                'name'               => '  Increase/(decrease) in Tax Payable',
                'amount'             =>  $cur_ind['delta_tax_payable'],
                'comparative_amount' =>  $cmp_ind['delta_tax_payable'],
            ],
        ];
        $net_operating_cur = $cur_ind['net_operating'];
        $net_operating_cmp = $cmp_ind['net_operating'];

        // Reconciliation gap (indirect − direct). In a fully-balanced
        // double-entry ledger this is 0. Today it usually isn't, because
        // operations don't auto-post to the canonical ledger yet (Phase 4).
        $reconciliation_diff_cur = $net_operating_cur - $cur['net_operating'];
        $reconciliation_diff_cmp = $net_operating_cmp - $cmp['net_operating'];
    } else {
        $operating_lines = array_values(array_filter([
            $buildLine('Cash from customers',         $cur['cash_from_customers'],  $cmp['cash_from_customers']),
            $buildLine('Cash paid to suppliers',     -$cur['cash_to_suppliers'],   -$cmp['cash_to_suppliers']),
            $buildLine('Salaries paid',              -$cur['salaries_paid'],       -$cmp['salaries_paid']),
            $buildLine('Other operating expenses',   -$cur['other_opex_paid'],     -$cmp['other_opex_paid']),
        ]));
        $net_operating_cur = $cur['net_operating'];
        $net_operating_cmp = $cmp['net_operating'];
        $reconciliation_diff_cur = null;
        $reconciliation_diff_cmp = null;
    }

    $investing_lines = array_values(array_filter([
        $buildLine('Purchase of fixed assets',   -$cur['asset_purchases'],     -$cmp['asset_purchases']),
    ]));

    $financing_lines = [];   // empty by design

    // ───────────────────────────────────────────────────────────────────────
    // Phase 3.3 — IFRS for SMEs §7 disclosure additions
    //
    // §7.19A — Reconciliation of liabilities arising from financing activities
    // §7.19B-C — Supplier finance arrangements
    //
    // Both disclosures are returned in data.disclosures for current AND
    // comparative periods so the Phase 3.4 UI can render them. Empty
    // shapes are still returned with applicable=false so the UI has a
    // consistent contract to render the deferral note against.
    // ───────────────────────────────────────────────────────────────────────

    /**
     * §7.19A snapshot — financing-liabilities reconciliation. BMS has no
     * company-borrowed-loan tracking (loans excluded per project policy),
     * so this disclosure is always empty with a single applicable=false flag.
     */
    $financingLiabilitiesDisclosure = function (string $from, string $to): array {
        return [
            'applicable'        => false,
            'note'              => 'No financing liabilities tracked in this system (borrowings, share capital changes, and dividends excluded per company policy).',
            'opening_balance'   => 0.0,
            'cash_changes'      => 0.0,
            'non_cash_changes'  => 0.0,
            'closing_balance'   => 0.0,
        ];
    };

    /**
     * §7.19B-C snapshot — supplier finance arrangements. BMS doesn't track
     * formal supplier-finance programs (reverse factoring, dynamic
     * discounting). We expose the closest proxy: unpaid approved supplier
     * invoices outstanding at the period end, with computed due dates
     * resolved through purchase_orders.payment_terms.
     *
     * Due-date computation:
     *   - JOIN supplier_invoices.po_id -> purchase_orders.payment_terms
     *   - Parse 'net_N' strings (e.g. 'net_30') as N days after date_recorded
     *   - Any other value (other / null / empty / unparseable) falls back to
     *     date_recorded itself
     */
    $supplierFinanceDisclosure = function (string $as_of) use ($pdo, $scopeClause, $project_id): array {
        $scope = $scopeClause('si.project_id', 'si');

        // Computed due_date column:
        //   when payment_terms looks like 'net_NN' -> add NN days to date_recorded
        //   otherwise                              -> use date_recorded
        $due_date_expr = "
            CASE
                WHEN po.payment_terms LIKE 'net_%'
                  AND CAST(SUBSTRING_INDEX(po.payment_terms, '_', -1) AS UNSIGNED) > 0
                THEN DATE_ADD(si.date_recorded,
                              INTERVAL CAST(SUBSTRING_INDEX(po.payment_terms, '_', -1) AS UNSIGNED) DAY)
                ELSE si.date_recorded
            END
        ";

        $sql = "
            SELECT
                COUNT(*)                                              AS invoice_count,
                COALESCE(SUM(si.amount), 0)                           AS total_unpaid_amount,
                MIN({$due_date_expr})                                 AS earliest_due_date,
                MAX({$due_date_expr})                                 AS latest_due_date,
                COUNT(CASE WHEN po.payment_terms LIKE 'net_%' THEN 1 END) AS invoices_with_terms
              FROM supplier_invoices si
         LEFT JOIN purchase_orders po ON si.po_id = po.purchase_order_id
             WHERE si.status = 'approved'
               AND si.payment_date IS NULL
               AND si.date_recorded <= ?
               {$scope['sql']}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$as_of], $scope['params']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $count = (int)($row['invoice_count'] ?? 0);
        return [
            'applicable'           => false,
            'note'                 => 'No formal supplier finance arrangements (reverse factoring, dynamic discounting, etc.) are tracked in this system. The figures below show all unpaid approved supplier invoices outstanding as at the report date, with due dates computed from the linked purchase order\'s payment_terms (e.g. "net_30" = date_recorded + 30 days); when no parseable terms exist, date_recorded is used as the proxy.',
            'invoice_count'        => $count,
            'invoices_with_terms'  => (int)($row['invoices_with_terms'] ?? 0),
            'total_unpaid_amount'  => (float)($row['total_unpaid_amount'] ?? 0),
            'earliest_due_date'    => $row['earliest_due_date'] ?? null,
            'latest_due_date'      => $row['latest_due_date'] ?? null,
        ];
    };

    $disclosures = [
        'financing_liabilities_reconciliation' => [
            'current'     => $financingLiabilitiesDisclosure($start_date, $end_date),
            'comparative' => $financingLiabilitiesDisclosure($comparative_start, $comparative_end),
        ],
        'supplier_finance_arrangements' => [
            'current'     => $supplierFinanceDisclosure($end_date),
            'comparative' => $supplierFinanceDisclosure($comparative_end),
        ],
    ];

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
    // net_change_in_cash uses the per-method operating total. Investing +
    // financing are method-independent.
    $net_change_cur = $net_operating_cur + $cur['net_investing'] + $cur['net_financing'];
    $net_change_cmp = $net_operating_cmp + $cmp['net_investing'] + $cmp['net_financing'];

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
        $opening_cash = $closing_cash - $net_change_cur;
        $cmp_closing_cash = $opening_cash;
        $cmp_opening_cash = $cmp_closing_cash - $net_change_cmp;
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
                'method'                    => $method,
                'operating_reconciliation_difference' => [
                    'current'     => $reconciliation_diff_cur,
                    'comparative' => $reconciliation_diff_cmp,
                ],
            ],
            'sections' => [
                'operating' => [
                    'lines'             => $operating_lines,
                    'total'             => $net_operating_cur,
                    'comparative_total' => $net_operating_cmp,
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
                'net_change_in_cash' => $net_change_cur,
                'comparative' => [
                    'net_change_in_cash' => $net_change_cmp,
                ],
            ],
            'disclosures' => $disclosures,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Cash Flow API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
