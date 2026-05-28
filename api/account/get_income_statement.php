<?php
/**
 * Income Statement (Profit & Loss) — Data API
 *
 * Phase 3 rewrite. Returns a fully-structured P&L for the requested
 * period — including server-side computed Gross Profit, Operating
 * Profit, Net Profit — so the client side just renders rather than
 * computes (the previous JS-side total computation was fragile).
 *
 * Classification source (canonical, from Phase 1):
 *   accounts.account_type_id  → account_types.type_id
 *                              → account_types.category ∈ {revenue, cogs, expense}
 *
 * REPLACES the legacy `accounts.account_type = 'income'` /
 * `LIKE '%Salaries%'` queries which silently missed any account whose
 * type wasn't on a hard-coded short list.
 *
 * Filters:
 *   - je.status = 'posted' (always)
 *   - je.entry_date BETWEEN ? AND ? (current period)
 *   - Previous-period comparison: same length, immediately prior
 *
 * Returns JSON:
 *   {
 *     success: true,
 *     data: {
 *       meta: { current_start, current_end, prev_start, prev_end,
 *               unclassified_count, posting_warning }
 *       sections: {
 *         revenue: { lines: [{code,name,current,previous}], total_current, total_previous }
 *         cogs:    { ... }
 *         expense: { ... }
 *       }
 *       totals: {
 *         total_revenue, total_cogs, total_expenses,
 *         gross_profit, gross_margin_pct,
 *         net_profit,   net_margin_pct,
 *         previous: { total_revenue, total_cogs, total_expenses, gross_profit, net_profit }
 *       }
 *     }
 *   }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/financial_classification.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ── Date parameters ────────────────────────────────────────────────────
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');

// Previous-period comparison — same length, immediately prior.
// Default behavior matches the legacy API (1 month back); for multi-
// month ranges we fall back to "same length immediately prior".
try {
    $ts_start = strtotime($start_date);
    $ts_end   = strtotime($end_date);
    $span_days = (int) round(($ts_end - $ts_start) / 86400);
    $prev_end_date   = date('Y-m-d', strtotime($start_date . ' -1 day'));
    $prev_start_date = date('Y-m-d', strtotime($prev_end_date . ' -' . max($span_days, 0) . ' days'));
} catch (Throwable $e) {
    $prev_start_date = date('Y-m-01', strtotime($start_date . ' -1 month'));
    $prev_end_date   = date('Y-m-t', strtotime($end_date . ' -1 month'));
}

try {
    global $pdo;

    // ── Pull P&L type_ids from canonical classification ────────────────
    $revenue_type_ids = fc_type_ids_for_categories($pdo, ['revenue']);
    $cogs_type_ids    = fc_type_ids_for_categories($pdo, ['cogs']);
    $expense_type_ids = fc_type_ids_for_categories($pdo, ['expense']);

    $unclassified = fc_unclassified_types($pdo);

    /**
     * Helper: build line items for a given category ($type_ids list),
     * using the natural side (revenue=credit; cogs/expense=debit).
     *
     * Returns ['lines' => [...], 'total_current' => x, 'total_previous' => y]
     */
    $fetchSection = function (array $type_ids, string $direction) use ($pdo, $start_date, $end_date, $prev_start_date, $prev_end_date) {
        if (empty($type_ids)) {
            return ['lines' => [], 'total_current' => 0.0, 'total_previous' => 0.0];
        }
        $ph = implode(',', array_fill(0, count($type_ids), '?'));

        // Natural-side amount expression:
        //   - revenue (credit-natural) → SUM(credit) - SUM(debit)
        //   - expense / cogs (debit-natural) → SUM(debit) - SUM(credit)
        if ($direction === 'credit') {
            $cur = "COALESCE(SUM(CASE WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
            $prv = "COALESCE(SUM(CASE WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
        } else { // 'debit'
            $cur = "COALESCE(SUM(CASE WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
            $prv = "COALESCE(SUM(CASE WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
        }

        $sql = "
            SELECT a.account_id,
                   a.account_code,
                   a.account_name,
                   $cur AS current_period,
                   $prv AS previous_period
              FROM accounts a
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries je      ON je.entry_id    = jei.entry_id
             WHERE a.account_type_id IN ($ph)
               AND a.status = 'active'
          GROUP BY a.account_id, a.account_code, a.account_name
          HAVING ABS(current_period) > 0.001 OR ABS(previous_period) > 0.001
          ORDER BY a.account_code
        ";

        $params = array_merge(
            // current x2 inside the CASE for the current_period column
            [$start_date, $end_date, $start_date, $end_date],
            // previous x2 inside the CASE for the previous_period column
            [$prev_start_date, $prev_end_date, $prev_start_date, $prev_end_date],
            // type_ids for the IN(...) filter
            $type_ids
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_current  = 0.0;
        $total_previous = 0.0;
        $lines = [];
        foreach ($rows as $r) {
            $cv = (float) $r['current_period'];
            $pv = (float) $r['previous_period'];
            $total_current  += $cv;
            $total_previous += $pv;
            $lines[] = [
                'account_id'    => (int) $r['account_id'],
                'account_code'  => $r['account_code'],
                'account_name'  => $r['account_name'],
                'current'       => $cv,
                'previous'      => $pv,
            ];
        }
        return ['lines' => $lines, 'total_current' => $total_current, 'total_previous' => $total_previous];
    };

    $revenue = $fetchSection($revenue_type_ids, 'credit');
    $cogs    = $fetchSection($cogs_type_ids,    'debit');
    $expense = $fetchSection($expense_type_ids, 'debit');

    // ── Sales Returns processed in the period (informational) ─────────
    // We pull the `sales_returns` table directly rather than guessing how
    // returns are journaled. Shown as a sub-line under Revenue for the
    // accountant's reference; not subtracted from the categorical totals
    // (those already reflect whatever journal entries the return posted).
    $sales_returns_current  = 0.0;
    $sales_returns_previous = 0.0;
    try {
        $srExists = $pdo->query("SHOW TABLES LIKE 'sales_returns'")->fetch();
        if ($srExists) {
            $sr = $pdo->prepare("
                SELECT COALESCE(SUM(grand_total), 0)
                  FROM sales_returns
                 WHERE return_date BETWEEN ? AND ?
                   AND status IN ('approved','refunded')
            ");
            $sr->execute([$start_date, $end_date]);
            $sales_returns_current = (float) $sr->fetchColumn();

            $sr->execute([$prev_start_date, $prev_end_date]);
            $sales_returns_previous = (float) $sr->fetchColumn();
        }
    } catch (Throwable $e) {
        // sales_returns is not yet on this server — silently degrade to 0.
        $sales_returns_current  = 0.0;
        $sales_returns_previous = 0.0;
    }

    // ── Server-side totals — full professional ordering ───────────────
    //
    // Income Statement layout (Tanzanian SME — loans excluded):
    //
    //   Revenue
    //   (informational) Sales Returns processed this period
    //   Less: Cost of Goods Sold
    //   = GROSS PROFIT
    //   Less: Operating Expenses
    //   = OPERATING PROFIT (EBIT)
    //   Less: Income Tax Expense
    //   = PROFIT BEFORE TAX     ← same as EBIT until Other Income/Expense is added
    //   = NET PROFIT FOR PERIOD ← = PBT − Tax
    //
    // Income tax is left at 0 until the accountant starts posting a monthly
    // tax provision (Dr Income Tax Expense / Cr Tax Payable). When they do,
    // the line populates automatically because tax-expense accounts already
    // flow into the `expense` category and we subtract them then.
    $tr  = $revenue['total_current'];
    $tc  = $cogs['total_current'];
    $te  = $expense['total_current'];

    $gp                = $tr - $tc;                      // Gross Profit
    $operating_profit  = $gp - $te;                      // Operating Profit (EBIT)
    $income_tax        = 0.0;                            // placeholder until tax-account flagging exists
    $profit_before_tax = $operating_profit;              // = EBIT until Other Inc/Exp added
    $np                = $profit_before_tax - $income_tax;  // Net Profit For Period

    $gpm = $tr > 0.001 ? round(($gp / $tr) * 100, 1) : 0.0;
    $opm = $tr > 0.001 ? round(($operating_profit / $tr) * 100, 1) : 0.0;
    $npm = $tr > 0.001 ? round(($np / $tr) * 100, 1) : 0.0;

    // Previous-period parallel totals
    $tr_p   = $revenue['total_previous'];
    $tc_p   = $cogs['total_previous'];
    $te_p   = $expense['total_previous'];
    $gp_p   = $tr_p - $tc_p;
    $op_p   = $gp_p - $te_p;
    $pbt_p  = $op_p;
    $np_p   = $pbt_p;

    // Posting warning if there are any draft entries in the period — accountants
    // need to know that the report excludes them.
    $draftStmt = $pdo->prepare("
        SELECT COUNT(*) FROM journal_entries
         WHERE entry_date BETWEEN ? AND ?
           AND status != 'posted'
    ");
    $draftStmt->execute([$start_date, $end_date]);
    $draft_count = (int) $draftStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'       => $start_date,
                'current_end'         => $end_date,
                'prev_start'          => $prev_start_date,
                'prev_end'            => $prev_end_date,
                'unclassified_count'  => count($unclassified),
                'unclassified_types'  => $unclassified,
                'draft_count'         => $draft_count,
            ],
            'sections' => [
                'revenue' => $revenue,
                'cogs'    => $cogs,
                'expense' => $expense,
            ],
            'totals' => [
                'total_revenue'         => $tr,
                'sales_returns'         => $sales_returns_current,
                'total_cogs'            => $tc,
                'gross_profit'          => $gp,
                'gross_margin_pct'      => $gpm,
                'total_expenses'        => $te,
                'operating_profit'      => $operating_profit,
                'operating_margin_pct'  => $opm,
                'income_tax'            => $income_tax,
                'profit_before_tax'     => $profit_before_tax,
                'net_profit'            => $np,
                'net_margin_pct'        => $npm,
                'previous' => [
                    'total_revenue'     => $tr_p,
                    'sales_returns'     => $sales_returns_previous,
                    'total_cogs'        => $tc_p,
                    'gross_profit'      => $gp_p,
                    'total_expenses'    => $te_p,
                    'operating_profit'  => $op_p,
                    'income_tax'        => 0.0,
                    'profit_before_tax' => $pbt_p,
                    'net_profit'        => $np_p,
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log("Income Statement API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
