<?php
/**
 * Balance Sheet — Data API
 *
 * Point-in-time snapshot at `as_of_date`. Hybrid data sources (same pattern
 * as get_income_statement.php): operational tables for the things actually
 * populated in BMS, supplemented with posted manual journal entries.
 *
 * Per project guidance: LOANS are excluded entirely (no liability side
 * tracking exists — `loans` table holds money LENT to customers; that's an
 * asset, but it is intentionally excluded per user instruction).
 *
 * ASSETS
 *   Cash & Bank             = SUM(accounts.current_balance) WHERE type=asset
 *                             AND name matches bank/cash patterns
 *   Accounts Receivable     = SUM(invoices.balance_due) WHERE status NOT IN
 *                             ('paid','cancelled') AND invoice_date <= as_of_date
 *   Inventory               = SUM(product_stocks.stock_quantity *
 *                             COALESCE(products.cost_price, 0))
 *   Fixed Assets (at cost)  = SUM(assets.cost) WHERE purchase_date <= as_of_date
 *
 * LIABILITIES
 *   Accounts Payable        = SUM(supplier_invoices.amount) WHERE status='approved'
 *                             AND payment_date IS NULL AND date_recorded <= as_of_date
 *   Salaries Payable        = SUM(payroll.net_salary) WHERE payment_status != 'paid'
 *
 * EQUITY
 *   Opening Balance Equity  = SUM(accounts.current_balance) WHERE type=equity
 *   Current Year Net Profit = mini-IS computation: Revenue − COGS − Expenses for
 *                             the period (start of current year) → as_of_date
 *   Retained Earnings (plug)= TOTAL_ASSETS − TOTAL_LIABILITIES − OpeningEquity
 *                             − CurrentYearProfit  (whatever balances the sheet)
 *
 * Project filter: when project_id is provided AND in scope, narrows the
 * project-taggable rows (AR, AP). Cash, Inventory, Fixed Assets, Salaries
 * Payable, Opening Equity, and Current Year Profit are company-wide and
 * shown as 0 with a banner.
 *
 * User scope: admin sees everything. Non-admin sees their assigned projects
 * + untagged company-wide rows for the consolidated view via the canonical
 * scopeFilterSqlNullable() helper.
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta:     { as_of_date, project_id, project_filter_active, is_admin,
 *                   scoped_project_ids, current_year_start },
 *       sections: {
 *           assets:      { lines, total }
 *           liabilities: { lines, total }
 *           equity:      { lines, total }
 *       }
 *       totals: { total_assets, total_liabilities, total_equity,
 *                 liab_plus_equity, balanced, balance_difference }
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

// ── Parameters ───────────────────────────────────────────────────────────
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Current-year window for Net Profit calculation.
$current_year_start = date('Y-01-01', strtotime($as_of_date));

// ── Scope resolution ────────────────────────────────────────────────────
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

    // ─── Project-scope clause helper (same shape as IS) ──────────────────
    $scopeClause = function (string $col, string $alias = '') use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        }
        return ['sql' => scopeFilterSqlNullable('project', $alias), 'params' => []];
    };

    // ─── ASSETS ──────────────────────────────────────────────────────────

    // Cash & Bank: pulled from accounts.current_balance for asset-typed
    // accounts whose name matches bank/cash patterns. Hidden under a
    // specific-project view (not project-attributable).
    $cash_bank = 0.0;
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
                    OR account_name LIKE '%Equity Bank%'
                    OR account_name LIKE '%mpesa%'
                    OR account_name LIKE '%mobile money%')
        ");
        $cash_bank = (float)$stmt->fetchColumn();
    }

    // Accounts Receivable: unpaid invoices' balance_due as of date.
    $scope = $scopeClause('project_id', '');
    $sql = "SELECT COALESCE(SUM(balance_due), 0)
              FROM invoices
             WHERE status NOT IN ('paid','cancelled')
               AND invoice_date <= ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$as_of_date], $scope['params']));
    $ar = (float)$stmt->fetchColumn();

    // Inventory: stock × cost. Hidden under specific-project view.
    $inventory = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(ps.stock_quantity * COALESCE(p.cost_price, 0)), 0)
              FROM product_stocks ps
         LEFT JOIN products p ON p.product_id = ps.product_id
             WHERE ps.stock_quantity > 0
        ");
        $inventory = (float)$stmt->fetchColumn();
    }

    // Fixed Assets at cost. Hidden under specific-project view.
    $fixed_assets = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(cost), 0)
              FROM assets
             WHERE purchase_date <= ?
               AND (status IS NULL OR status != 'disposed')
        ");
        $stmt->execute([$as_of_date]);
        $fixed_assets = (float)$stmt->fetchColumn();
    }

    // ─── LIABILITIES ─────────────────────────────────────────────────────

    // Accounts Payable: supplier invoices approved but not yet paid.
    $scope = $scopeClause('project_id', '');
    $sql = "SELECT COALESCE(SUM(amount), 0)
              FROM supplier_invoices
             WHERE status = 'approved'
               AND payment_date IS NULL
               AND date_recorded <= ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$as_of_date], $scope['params']));
    $ap = (float)$stmt->fetchColumn();

    // Salaries Payable: payroll approved but not yet paid. No project_id on
    // payroll, so hidden under specific-project view.
    $salaries_payable = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(net_salary), 0)
              FROM payroll
             WHERE (payment_status IS NULL OR payment_status != 'paid')
               AND status != 'cancelled'
        ");
        $salaries_payable = (float)$stmt->fetchColumn();
    }

    // ─── EQUITY ──────────────────────────────────────────────────────────

    // Opening Balance Equity: equity-typed accounts. Hidden under specific
    // project view.
    $opening_equity = 0.0;
    if ($project_id === null) {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(current_balance), 0)
              FROM accounts
             WHERE account_type_id = 3
               AND status = 'active'
        ");
        $opening_equity = (float)$stmt->fetchColumn();
    }

    // Current Year Net Profit (Jan 1 → as_of_date).
    // We compute a mini Income Statement: Sales + IPCs − SalesReturns − COGS
    //   − Expenses − Compensation.
    // Mirrors the rules from get_income_statement.php (cash-basis).
    $cur_revenue = 0.0;
    $cur_cogs = 0.0;
    $cur_expenses = 0.0;

    // Revenue: paid invoices (net of tax)
    $scope = $scopeClause('project_id', '');
    $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
              FROM invoices
             WHERE status = 'paid'
               AND payment_date BETWEEN ? AND ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$current_year_start, $as_of_date], $scope['params']));
    $cur_revenue += (float)$stmt->fetchColumn();

    // Revenue: paid IPCs
    $sql = "SELECT COALESCE(SUM(certified_amount), 0)
              FROM interim_payment_certificates
             WHERE status = 'Paid' AND invoice_id IS NULL
               AND ipc_date BETWEEN ? AND ?"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$current_year_start, $as_of_date], $scope['params']));
    $cur_revenue += (float)$stmt->fetchColumn();

    // Less Sales Returns
    try {
        $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_returns'")->fetch();
    } catch (Throwable $e) { $tableExists = false; }
    if ($tableExists) {
        $scope = $scopeClause('i.project_id', 'i');
        $sql = "SELECT COALESCE(SUM(sr.grand_total - sr.total_tax), 0)
                  FROM sales_returns sr
             LEFT JOIN invoices i ON sr.invoice_id = i.invoice_id
                 WHERE sr.status = 'refunded'
                   AND sr.return_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$current_year_start, $as_of_date], $scope['params']));
        $cur_revenue -= (float)$stmt->fetchColumn();
    }

    // COGS Trading
    $scope = $scopeClause('i.project_id', 'i');
    $sql = "SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
              FROM invoices i
        INNER JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
        INNER JOIN products p ON p.product_id = ii.product_id
             WHERE i.status = 'paid'
               AND i.payment_date BETWEEN ? AND ?
               AND ii.product_id IS NOT NULL"
         . $scope['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$current_year_start, $as_of_date], $scope['params']));
    $cur_cogs += (float)$stmt->fetchColumn();

    // COGS Project Direct + Operating Expenses, with the Path B rule.
    // Both pull from `expenses`; the split is based on project_id presence.
    // Salaries (payroll.paid) is operating expense.
    $scope_pd = $project_id !== null
        ? ['sql' => " AND e.project_id = ?", 'params' => [$project_id]]
        : ($is_admin
            ? ['sql' => " AND e.project_id IS NOT NULL", 'params' => []]
            : (empty($user_project_ids)
                ? ['sql' => " AND 0", 'params' => []]
                : ['sql' => " AND e.project_id IN (" . implode(',', $user_project_ids) . ")", 'params' => []]));
    $sql = "SELECT COALESCE(SUM(e.amount), 0)
              FROM expenses e
             WHERE e.status = 'paid'
               AND e.payroll_id IS NULL
               AND e.expense_date BETWEEN ? AND ?"
         . $scope_pd['sql'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$current_year_start, $as_of_date], $scope_pd['params']));
    $cur_cogs += (float)$stmt->fetchColumn();

    // General Operating Expenses (project_id IS NULL) — hidden when specific
    // project is selected (overhead is company-wide).
    if ($project_id === null) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(e.amount), 0)
              FROM expenses e
             WHERE e.status = 'paid'
               AND e.payroll_id IS NULL
               AND e.project_id IS NULL
               AND e.expense_date BETWEEN ? AND ?
        ");
        $stmt->execute([$current_year_start, $as_of_date]);
        $cur_expenses += (float)$stmt->fetchColumn();

        // Compensation: payroll (no project_id, so hidden under specific filter).
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(net_salary), 0)
              FROM payroll
             WHERE payment_status = 'paid'
               AND payment_date BETWEEN ? AND ?
        ");
        $stmt->execute([$current_year_start, $as_of_date]);
        $cur_expenses += (float)$stmt->fetchColumn();
    }

    $current_year_net_profit = $cur_revenue - $cur_cogs - $cur_expenses;

    // Retained Earnings as the balancing plug. After the user properly
    // categorises equity accounts, this should approach zero.
    $total_assets      = $cash_bank + $ar + $inventory + $fixed_assets;
    $total_liabilities = $ap + $salaries_payable;
    $retained_earnings = $total_assets - $total_liabilities - $opening_equity - $current_year_net_profit;

    // ─── Compose line-level output ───────────────────────────────────────
    $assets_lines = [];
    if ($cash_bank      != 0) $assets_lines[] = ['name' => 'Cash & Bank',          'amount' => $cash_bank];
    if ($ar             != 0) $assets_lines[] = ['name' => 'Accounts Receivable',  'amount' => $ar];
    if ($inventory      != 0) $assets_lines[] = ['name' => 'Inventory',            'amount' => $inventory];
    if ($fixed_assets   != 0) $assets_lines[] = ['name' => 'Fixed Assets (at cost)', 'amount' => $fixed_assets];

    $liab_lines = [];
    if ($ap               != 0) $liab_lines[] = ['name' => 'Accounts Payable',    'amount' => $ap];
    if ($salaries_payable != 0) $liab_lines[] = ['name' => 'Salaries Payable',    'amount' => $salaries_payable];

    $equity_lines = [];
    if ($opening_equity != 0) $equity_lines[] = ['name' => 'Opening Balance Equity', 'amount' => $opening_equity];
    $equity_lines[] = ['name' => 'Current Year Net Profit', 'amount' => $current_year_net_profit];
    $equity_lines[] = ['name' => 'Retained Earnings (computed)', 'amount' => $retained_earnings];

    $total_equity      = $opening_equity + $current_year_net_profit + $retained_earnings;
    $liab_plus_equity  = $total_liabilities + $total_equity;
    $balanced          = abs($total_assets - $liab_plus_equity) < 0.5;

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'as_of_date'           => $as_of_date,
                'current_year_start'   => $current_year_start,
                'project_id'           => $project_id,
                'project_filter_active'=> $project_id !== null,
                'is_admin'             => $is_admin,
                'scoped_project_ids'   => $is_admin ? null : $user_project_ids,
            ],
            'sections' => [
                'assets'      => ['lines' => $assets_lines, 'total' => $total_assets],
                'liabilities' => ['lines' => $liab_lines,   'total' => $total_liabilities],
                'equity'      => ['lines' => $equity_lines, 'total' => $total_equity],
            ],
            'totals' => [
                'total_assets'        => $total_assets,
                'total_liabilities'   => $total_liabilities,
                'total_equity'        => $total_equity,
                'liab_plus_equity'    => $liab_plus_equity,
                'balanced'            => $balanced,
                'balance_difference'  => $total_assets - $liab_plus_equity,
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Balance Sheet API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
