<?php
/**
 * Income Statement (Profit & Loss) — Data API
 *
 * Operational + manual-journals hybrid implementation per the agreed plan.
 *
 * REVENUE
 *   Sales of Goods & Services         = invoices.paid (grand_total − tax_amount), payment_date in period
 *   Contract Revenue (IPCs)           = interim_payment_certificates.Paid (certified_amount)
 *                                       — only IPCs with invoice_id IS NULL (avoids double-count with linked invoice)
 *   Less: Sales Returns               = sales_returns.refunded (grand_total − total_tax)
 *   + Manual Revenue Journals         = journal_entries.posted on revenue-category accounts
 *
 * COGS (Path B: product cost + project direct cost)
 *   Cost of Goods Sold (Trading)      = SUM(invoice_items.quantity × products.cost_price)
 *                                       for invoices.paid with product_id IS NOT NULL
 *   Project Direct Costs              = expenses.paid where project_id IS NOT NULL,
 *                                       grouped by expense_categories.name, payroll_id IS NULL
 *   + Manual COGS Journals            = journal_entries.posted on cogs-category accounts
 *
 * OPERATING EXPENSES
 *   General Expenses                  = expenses.paid where project_id IS NULL, payroll_id IS NULL,
 *                                       grouped by expense_categories.name
 *   Compensation (Salaries)           = payroll.paid (net_salary)
 *   Depreciation                      = 0 placeholder
 *   + Manual Expense Journals         = journal_entries.posted on expense-category accounts
 *
 * Project filter:
 *   When ?project_id=N is provided:
 *     - All revenue + project-direct cost queries narrow to that project
 *     - General Expenses and Compensation show 0 (company-wide, not project-tagged)
 *     - Manual journals are EXCLUDED (no project_id on journal_entries)
 *     - Frontend banner explains the manual-journal exclusion
 *
 * Returns JSON shape compatible with the existing frontend.
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

// ── Date + project parameters ─────────────────────────────────────────────
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// ── Scope resolution ──────────────────────────────────────────────────────
// Admins see everything. Non-admins are restricted to projects assigned to
// them via $_SESSION['scope']['projects'] PLUS untagged ("non of any project")
// data (general overhead like office rent, salaries, sales without
// project_id) when viewing "All My Projects". When a non-admin requests a
// specific project, it must be one of their assigned projects.
$is_admin = isAdmin();
$user_project_ids = [];
if (!$is_admin) {
    $user_project_ids = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
}

// Authorization gate: non-admin asking for a specific project not in scope.
if (!$is_admin && $project_id !== null && !in_array($project_id, $user_project_ids, true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied: this project is not in your assigned scope.',
    ]);
    exit;
}

// Previous period — same length, immediately prior
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

    // ───────────────────────────────────────────────────────────────────────
    // Project-scope clause builder
    //   - Specific project requested  → "AND <col> = ?"
    //   - Admin, no project specified → "" (sees everything)
    //   - Non-admin, no project, no assignments → "AND <col> IS NULL"
    //   - Non-admin, no project, has assignments → "AND (<col> IN (assigned) OR <col> IS NULL)"
    // The "OR IS NULL" piece is the smart twist: project managers see THEIR
    // projects' direct results plus their share of company-wide overhead.
    // ───────────────────────────────────────────────────────────────────────
    $scopeClause = function (string $col) use ($project_id, $is_admin, $user_project_ids): array {
        if ($project_id !== null) {
            return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        }
        if ($is_admin) {
            return ['sql' => '', 'params' => []];
        }
        if (empty($user_project_ids)) {
            return ['sql' => " AND $col IS NULL", 'params' => []];
        }
        $ph = implode(',', array_fill(0, count($user_project_ids), '?'));
        return [
            'sql'    => " AND ($col IN ($ph) OR $col IS NULL)",
            'params' => $user_project_ids,
        ];
    };

    // ───────────────────────────────────────────────────────────────────────
    // Helper closures — each returns scalars/rows for the requested window
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Sum of paid invoices' net revenue (grand_total - tax_amount) in window.
     */
    $sumSales = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('project_id');
        $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
                  FROM invoices
                 WHERE status = 'paid'
                   AND payment_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Sum of Paid IPCs' certified_amount (excluding those linked to an invoice).
     */
    $sumIPC = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('project_id');
        $sql = "SELECT COALESCE(SUM(certified_amount), 0)
                  FROM interim_payment_certificates
                 WHERE status = 'Paid'
                   AND invoice_id IS NULL
                   AND ipc_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Sum of refunded sales returns' net amount (grand_total - total_tax).
     * Project filter resolved via JOIN on invoices.project_id.
     */
    $sumSalesReturns = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('i.project_id');
        $sql = "SELECT COALESCE(SUM(sr.grand_total - sr.total_tax), 0)
                  FROM sales_returns sr
             LEFT JOIN invoices i ON sr.invoice_id = i.invoice_id
                 WHERE sr.status = 'refunded'
                   AND sr.return_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * COGS — product cost: SUM(invoice_items.quantity × products.cost_price)
     * for paid invoices in window with product_id set.
     */
    $sumProductCOGS = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('i.project_id');
        $sql = "SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
                  FROM invoices i
            INNER JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
            INNER JOIN products p       ON p.product_id  = ii.product_id
                 WHERE i.status = 'paid'
                   AND i.payment_date BETWEEN ? AND ?
                   AND ii.product_id IS NOT NULL"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Expense breakdown by category, controlled by project-tagging filter.
     *
     * $mode = 'project_direct': only expenses with project_id IS NOT NULL
     *                           (becomes COGS — project direct costs)
     * $mode = 'general':        only expenses with project_id IS NULL
     *                           (becomes Operating Expenses; suppressed when project filter is on)
     *
     * Returns rows: [{category, current, previous}, ...]
     * Always excludes rows where payroll_id IS NOT NULL (payroll counted separately).
     */
    $categorizedExpenses = function (string $cur_from, string $cur_to,
                                     string $prv_from, string $prv_to,
                                     string $mode) use ($pdo, $project_id, $is_admin, $user_project_ids): array {
        // General OpEx is suppressed under a specific-project view (it's
        // company-wide overhead, not attributable to one project).
        if ($project_id !== null && $mode === 'general') {
            return [];
        }

        // Build the project-tagging clause for THIS query, per mode + scope.
        //
        // mode='project_direct' (becomes COGS Project-Direct):
        //   - Specific project P             → e.project_id = P
        //   - Admin "All Projects"           → e.project_id IS NOT NULL
        //   - Non-admin "All My Projects"    → e.project_id IN (assigned)
        //   - Non-admin empty scope          → return [] (no project-direct rows possible)
        //
        // mode='general' (becomes Operating Expenses):
        //   - Specific project P             → handled above (return [])
        //   - Admin / non-admin no project   → e.project_id IS NULL (overhead)
        $projectClause = '';
        $projectParams = [];
        if ($mode === 'project_direct') {
            if ($project_id !== null) {
                $projectClause = "AND e.project_id = ?";
                $projectParams = [$project_id];
            } elseif ($is_admin) {
                $projectClause = "AND e.project_id IS NOT NULL";
            } elseif (empty($user_project_ids)) {
                return [];
            } else {
                $ph = implode(',', array_fill(0, count($user_project_ids), '?'));
                $projectClause = "AND e.project_id IN ($ph)";
                $projectParams = $user_project_ids;
            }
        } else { // 'general'
            $projectClause = "AND e.project_id IS NULL";
        }

        $sql = "
            SELECT
                COALESCE(ec.name, 'Uncategorized') AS category,
                COALESCE(SUM(CASE WHEN e.expense_date BETWEEN ? AND ? THEN e.amount ELSE 0 END), 0) AS current_period,
                COALESCE(SUM(CASE WHEN e.expense_date BETWEEN ? AND ? THEN e.amount ELSE 0 END), 0) AS previous_period
              FROM expenses e
         LEFT JOIN expense_categories ec ON e.category_id = ec.id
             WHERE e.status = 'paid'
               AND e.payroll_id IS NULL
               {$projectClause}
               AND e.expense_date BETWEEN ? AND ?
          GROUP BY ec.id, ec.name
            HAVING ABS(current_period) > 0.001 OR ABS(previous_period) > 0.001
          ORDER BY current_period DESC, category
        ";

        $params = array_merge(
            [$cur_from, $cur_to],   // CASE for current
            [$prv_from, $prv_to],   // CASE for previous
            $projectParams,         // project-scope params
            [min($cur_from, $prv_from), max($cur_to, $prv_to)]  // outer date union
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    /**
     * Compensation expense from payroll. Hidden when a specific project is
     * selected (payroll has no project allocation in BMS today).
     *
     * NOTE: payroll has BOTH a `status` column (workflow status) and a
     * `payment_status` column (cash-out state). The canonical "paid =
     * money actually went out" trigger is payment_status. The status
     * column is never set to 'paid' in BMS — it stays 'pending' /
     * 'approved' for workflow tracking. We read payment_status here.
     */
    $sumCompensation = function (string $from, string $to) use ($pdo, $project_id): float {
        if ($project_id !== null) return 0.0;
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(net_salary), 0)
              FROM payroll
             WHERE payment_status = 'paid'
               AND payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$from, $to]);
        return (float) $stmt->fetchColumn();
    };

    /**
     * Manual journal entries posted to accounts of the given P&L category.
     * Returns per-account lines so the accountant can see which manual entries
     * are flowing into which section.
     *
     * EXCLUDED when a specific project is selected, because journal_entries
     * does not have a project_id column — including them would over-attribute
     * to the selected project. The frontend surfaces a banner explaining this.
     */
    $journalLines = function (array $type_ids, string $direction,
                              string $cur_from, string $cur_to,
                              string $prv_from, string $prv_to) use ($pdo, $project_id, $is_admin): array {
        // Manual journal entries have no project_id, so:
        //   - hidden under any specific-project view, and
        //   - hidden ALWAYS for non-admins (sensitive cross-cutting finance
        //     work; per agreed scope policy).
        if ($project_id !== null || empty($type_ids) || !$is_admin) return [];
        $ph = implode(',', array_fill(0, count($type_ids), '?'));

        if ($direction === 'credit') {
            $cur = "COALESCE(SUM(CASE WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
            $prv = "COALESCE(SUM(CASE WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
        } else {
            $cur = "COALESCE(SUM(CASE WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
            $prv = "COALESCE(SUM(CASE WHEN jei.type='debit'  AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN jei.amount
                                       WHEN jei.type='credit' AND je.entry_date BETWEEN ? AND ? AND je.status='posted' THEN -jei.amount
                                       ELSE 0 END), 0)";
        }

        $sql = "
            SELECT a.account_id, a.account_code, a.account_name,
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
            [$cur_from, $cur_to, $cur_from, $cur_to],
            [$prv_from, $prv_to, $prv_from, $prv_to],
            $type_ids
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'account_id'   => (int)$r['account_id'],
                'account_code' => $r['account_code'],
                'account_name' => 'Manual: ' . $r['account_name'],
                'current'      => (float)$r['current_period'],
                'previous'     => (float)$r['previous_period'],
            ];
        }
        return $out;
    };

    // ───────────────────────────────────────────────────────────────────────
    // REVENUE SECTION
    // ───────────────────────────────────────────────────────────────────────
    $rev_sales_cur     = $sumSales($start_date, $end_date);
    $rev_sales_prv     = $sumSales($prev_start_date, $prev_end_date);
    $rev_ipc_cur       = $sumIPC($start_date, $end_date);
    $rev_ipc_prv       = $sumIPC($prev_start_date, $prev_end_date);
    $sales_ret_cur     = $sumSalesReturns($start_date, $end_date);
    $sales_ret_prv     = $sumSalesReturns($prev_start_date, $prev_end_date);

    $revenue_type_ids  = fc_type_ids_for_categories($pdo, ['revenue']);
    $revenue_journals  = $journalLines($revenue_type_ids, 'credit',
        $start_date, $end_date, $prev_start_date, $prev_end_date);

    $revenue_lines = [];
    if (abs($rev_sales_cur) > 0.001 || abs($rev_sales_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Sales of Goods & Services',
            'current'      => $rev_sales_cur,
            'previous'     => $rev_sales_prv,
        ];
    }
    if (abs($rev_ipc_cur) > 0.001 || abs($rev_ipc_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Contract Revenue (IPCs)',
            'current'      => $rev_ipc_cur,
            'previous'     => $rev_ipc_prv,
        ];
    }
    if (abs($sales_ret_cur) > 0.001 || abs($sales_ret_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Less: Sales Returns',
            'current'      => -$sales_ret_cur,
            'previous'     => -$sales_ret_prv,
        ];
    }
    $revenue_lines = array_merge($revenue_lines, $revenue_journals);

    $journal_rev_cur = array_sum(array_column($revenue_journals, 'current'));
    $journal_rev_prv = array_sum(array_column($revenue_journals, 'previous'));

    $total_revenue_cur = $rev_sales_cur + $rev_ipc_cur - $sales_ret_cur + $journal_rev_cur;
    $total_revenue_prv = $rev_sales_prv + $rev_ipc_prv - $sales_ret_prv + $journal_rev_prv;

    // ───────────────────────────────────────────────────────────────────────
    // COGS SECTION (Path B: product cost + project direct cost)
    // ───────────────────────────────────────────────────────────────────────
    $cogs_prod_cur     = $sumProductCOGS($start_date, $end_date);
    $cogs_prod_prv     = $sumProductCOGS($prev_start_date, $prev_end_date);

    $cogs_proj_rows    = $categorizedExpenses(
        $start_date, $end_date,
        $prev_start_date, $prev_end_date,
        'project_direct'
    );

    $cogs_type_ids     = fc_type_ids_for_categories($pdo, ['cogs']);
    $cogs_journals     = $journalLines($cogs_type_ids, 'debit',
        $start_date, $end_date, $prev_start_date, $prev_end_date);

    $cogs_lines = [];
    if (abs($cogs_prod_cur) > 0.001 || abs($cogs_prod_prv) > 0.001) {
        $cogs_lines[] = [
            'account_code' => '',
            'account_name' => 'Cost of Goods Sold (Trading)',
            'current'      => $cogs_prod_cur,
            'previous'     => $cogs_prod_prv,
        ];
    }
    $cogs_proj_cur = 0.0;
    $cogs_proj_prv = 0.0;
    foreach ($cogs_proj_rows as $r) {
        $cogs_lines[] = [
            'account_code' => '',
            'account_name' => 'Project Direct: ' . $r['category'],
            'current'      => (float)$r['current_period'],
            'previous'     => (float)$r['previous_period'],
        ];
        $cogs_proj_cur += (float)$r['current_period'];
        $cogs_proj_prv += (float)$r['previous_period'];
    }
    $cogs_lines = array_merge($cogs_lines, $cogs_journals);

    $journal_cogs_cur = array_sum(array_column($cogs_journals, 'current'));
    $journal_cogs_prv = array_sum(array_column($cogs_journals, 'previous'));

    $total_cogs_cur = $cogs_prod_cur + $cogs_proj_cur + $journal_cogs_cur;
    $total_cogs_prv = $cogs_prod_prv + $cogs_proj_prv + $journal_cogs_prv;

    // ───────────────────────────────────────────────────────────────────────
    // OPERATING EXPENSES SECTION
    // ───────────────────────────────────────────────────────────────────────
    $opex_general_rows = $categorizedExpenses(
        $start_date, $end_date,
        $prev_start_date, $prev_end_date,
        'general'
    );

    $compensation_cur  = $sumCompensation($start_date, $end_date);
    $compensation_prv  = $sumCompensation($prev_start_date, $prev_end_date);

    $expense_type_ids  = fc_type_ids_for_categories($pdo, ['expense']);
    $expense_journals  = $journalLines($expense_type_ids, 'debit',
        $start_date, $end_date, $prev_start_date, $prev_end_date);

    $opex_lines = [];
    $opex_general_cur = 0.0;
    $opex_general_prv = 0.0;
    foreach ($opex_general_rows as $r) {
        $opex_lines[] = [
            'account_code' => '',
            'account_name' => $r['category'],
            'current'      => (float)$r['current_period'],
            'previous'     => (float)$r['previous_period'],
        ];
        $opex_general_cur += (float)$r['current_period'];
        $opex_general_prv += (float)$r['previous_period'];
    }
    if (abs($compensation_cur) > 0.001 || abs($compensation_prv) > 0.001) {
        $opex_lines[] = [
            'account_code' => '',
            'account_name' => 'Salaries & Wages',
            'current'      => $compensation_cur,
            'previous'     => $compensation_prv,
        ];
    }
    $opex_lines = array_merge($opex_lines, $expense_journals);

    $journal_exp_cur = array_sum(array_column($expense_journals, 'current'));
    $journal_exp_prv = array_sum(array_column($expense_journals, 'previous'));

    $total_expenses_cur = $opex_general_cur + $compensation_cur + $journal_exp_cur;
    $total_expenses_prv = $opex_general_prv + $compensation_prv + $journal_exp_prv;

    // ───────────────────────────────────────────────────────────────────────
    // TOTALS & RATIOS
    // ───────────────────────────────────────────────────────────────────────
    $gp_cur   = $total_revenue_cur - $total_cogs_cur;
    $gp_prv   = $total_revenue_prv - $total_cogs_prv;
    $op_cur   = $gp_cur - $total_expenses_cur;
    $op_prv   = $gp_prv - $total_expenses_prv;
    $tax_cur  = 0.0;
    $tax_prv  = 0.0;
    $pbt_cur  = $op_cur;       // = EBIT until finance costs / other income are added
    $pbt_prv  = $op_prv;
    $np_cur   = $pbt_cur - $tax_cur;
    $np_prv   = $pbt_prv - $tax_prv;

    $gpm = $total_revenue_cur > 0.001 ? round(($gp_cur  / $total_revenue_cur) * 100, 1) : 0.0;
    $opm = $total_revenue_cur > 0.001 ? round(($op_cur  / $total_revenue_cur) * 100, 1) : 0.0;
    $npm = $total_revenue_cur > 0.001 ? round(($np_cur  / $total_revenue_cur) * 100, 1) : 0.0;

    // ───────────────────────────────────────────────────────────────────────
    // WARNINGS
    // ───────────────────────────────────────────────────────────────────────
    $unclassified = fc_unclassified_types($pdo);

    $draftStmt = $pdo->prepare("
        SELECT COUNT(*) FROM journal_entries
         WHERE entry_date BETWEEN ? AND ?
           AND status != 'posted'
    ");
    $draftStmt->execute([$start_date, $end_date]);
    $draft_count = (int) $draftStmt->fetchColumn();

    // Unpaid payroll warning — read payment_status (the canonical cash-out
    // flag), not status (which is workflow only and never set to 'paid').
    $unpaidPayrollStmt = $pdo->prepare("
        SELECT COUNT(*) FROM payroll
         WHERE (payment_status IS NULL OR payment_status != 'paid')
           AND month BETWEEN MONTH(?) AND MONTH(?)
           AND year  BETWEEN YEAR(?)  AND YEAR(?)
    ");
    $unpaidPayrollStmt->execute([$start_date, $end_date, $start_date, $end_date]);
    $unpaid_payroll_count = (int) $unpaidPayrollStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'current_start'         => $start_date,
                'current_end'           => $end_date,
                'prev_start'            => $prev_start_date,
                'prev_end'              => $prev_end_date,
                'project_id'            => $project_id,
                'project_filter_active' => $project_id !== null,
                'is_admin'              => $is_admin,
                'scoped_project_ids'    => $is_admin ? null : $user_project_ids,
                'unclassified_count'    => count($unclassified),
                'unclassified_types'    => $unclassified,
                'draft_count'           => $draft_count,
                'unpaid_payroll_count'  => $unpaid_payroll_count,
            ],
            'sections' => [
                'revenue' => [
                    'lines'          => $revenue_lines,
                    'total_current'  => $total_revenue_cur,
                    'total_previous' => $total_revenue_prv,
                ],
                'cogs' => [
                    'lines'          => $cogs_lines,
                    'total_current'  => $total_cogs_cur,
                    'total_previous' => $total_cogs_prv,
                ],
                'expense' => [
                    'lines'          => $opex_lines,
                    'total_current'  => $total_expenses_cur,
                    'total_previous' => $total_expenses_prv,
                ],
            ],
            'totals' => [
                'total_revenue'        => $total_revenue_cur,
                'sales_returns'        => $sales_ret_cur,
                'total_cogs'           => $total_cogs_cur,
                'gross_profit'         => $gp_cur,
                'gross_margin_pct'     => $gpm,
                'total_expenses'       => $total_expenses_cur,
                'operating_profit'     => $op_cur,
                'operating_margin_pct' => $opm,
                'income_tax'           => $tax_cur,
                'profit_before_tax'    => $pbt_cur,
                'net_profit'           => $np_cur,
                'net_margin_pct'       => $npm,
                'previous' => [
                    'total_revenue'     => $total_revenue_prv,
                    'sales_returns'     => $sales_ret_prv,
                    'total_cogs'        => $total_cogs_prv,
                    'gross_profit'      => $gp_prv,
                    'total_expenses'    => $total_expenses_prv,
                    'operating_profit'  => $op_prv,
                    'income_tax'        => 0.0,
                    'profit_before_tax' => $pbt_prv,
                    'net_profit'        => $np_prv,
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log("Income Statement API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
