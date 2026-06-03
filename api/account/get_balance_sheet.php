<?php
/**
 * Balance Sheet — Data API (IFRS / TFRS-for-SMEs structure)
 *
 * Point-in-time snapshot at `as_of_date` with a comparative column for the
 * same date in the prior year. Layout follows the canonical structure that
 * a Tanzanian SME accountant would expect to sign off on:
 *
 *   ASSETS
 *     Current Assets
 *       Cash & Bank             (accounts.current_balance for bank/cash-typed
 *                                + petty_cash net balance
 *                                + cash register latest ending_cash per shift)
 *       Trade Receivables       (invoices.balance_due, unpaid as of date)
 *       Inventory               (product_stocks × products.cost_price)
 *     Non-Current Assets
 *       Property, Plant & Equipment
 *         At Cost               (assets.cost)
 *         Less: Accumulated Depreciation (assets.accumulated_depreciation)
 *         Net Book Value
 *
 *   EQUITY
 *     Share Capital             (system_settings 'share_capital_paid_in')
 *     Opening Balance Equity    (accounts.current_balance for equity-typed)
 *     Retained Earnings         (computed = total_assets − total_liab − rest of equity)
 *     Current Year Net Profit   (mini-IS from Jan 1 of as_of_date's year to as_of_date)
 *
 *   CURRENT LIABILITIES
 *     Trade Payables            (supplier_invoices approved + unpaid)
 *     Tax Payable               (proportional VAT owed on unpaid invoices)
 *     Salaries & Wages Payable  (payroll.payment_status != 'paid')
 *
 *   NON-CURRENT LIABILITIES
 *     (none — borrowings excluded per project scope)
 *
 *   STATEMENT OF CHANGES IN EQUITY
 *     Opening Equity (b/f)
 *     + Share Capital
 *     + Current Year Profit
 *     − Dividends paid (not tracked → 0)
 *     = Closing Equity
 *
 * Project filter + user scope: same model as Income Statement.
 *
 * Returns JSON shape:
 *   { success, data: {
 *       meta: { as_of_date, comparative_date, project_id, project_filter_active,
 *               is_admin, scoped_project_ids },
 *       sections: {
 *           current_assets         { lines, total, comparative_total }
 *           non_current_assets     { lines, total, comparative_total }
 *           current_liabilities    { lines, total, comparative_total }
 *           non_current_liabilities{ lines, total, comparative_total }
 *           equity                 { lines, total, comparative_total }
 *           changes_in_equity      { lines, opening, closing }
 *       },
 *       totals: { total_assets, total_liabilities, total_equity,
 *                 liab_plus_equity, balanced, balance_difference,
 *                 comparative: { total_assets, total_liabilities, total_equity } }
 *   } }
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/vat.php';

// Guarded: consumed as an internal report partial after headers are sent.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: account_types classification columns (migration 2026_05_27) must exist
// on this server, else every at.category query throws. Return a clear message.
try { $fc_ready = $pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch() !== false; }
catch (Throwable $e) { $fc_ready = false; }
if (!$fc_ready) {
    echo json_encode(['success' => false, 'message' =>
        'Report unavailable: account-type classification not installed on this server. '
      . 'Run migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
    exit;
}

// ── Parameters ───────────────────────────────────────────────────────────
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' && (int)$_GET['project_id'] > 0
    ? (int)$_GET['project_id']
    : null;

// Comparative = same calendar date, one year prior.
$comparative_date = date('Y-m-d', strtotime("$as_of_date -1 year"));
// Current-year start (for Net Profit window)
$current_year_start = date('Y-01-01', strtotime($as_of_date));
$prev_year_start    = date('Y-01-01', strtotime($comparative_date));

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

    // Project-scope clause builder — same shape as Income Statement.
    $scopeClause = function (string $col, string $alias = '') use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        }
        return ['sql' => scopeFilterSqlNullable('project', $alias), 'params' => []];
    };

    // ── Helper: detect a sales_returns table (graceful degrade) ─────────
    $hasSalesReturns = false;
    try {
        $hasSalesReturns = (bool)$pdo->query("SHOW TABLES LIKE 'sales_returns'")->fetch();
    } catch (Throwable $e) { $hasSalesReturns = false; }

    // ─────────────────────────────────────────────────────────────────────
    // computeAsOf — returns every BS section's amounts for a given date.
    // Called twice: once for current, once for comparative.
    // ─────────────────────────────────────────────────────────────────────
    $computeAsOf = function (string $date, string $yearStart) use (
        $pdo, $scopeClause, $project_id, $is_admin, $user_project_ids, $hasSalesReturns
    ): array {
        // ── CURRENT ASSETS ─────────────────────────────────────────────

        // Cash & Bank — multi-source. Only visible at company-wide scope
        // (no project_id) because cash is not project-attributable.
        $cash_bank = 0.0;
        $cash_breakdown = [];
        if ($project_id === null) {
            // Bank accounts in the chart of accounts
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(current_balance), 0)
                  FROM accounts
                 WHERE account_type_id = 1
                   AND status = 'active'
                   AND (account_name LIKE '%bank%' OR account_name LIKE '%cash%'
                        OR account_name LIKE '%CRDB%' OR account_name LIKE '%NMB%'
                        OR account_name LIKE '%Equity Bank%'
                        OR account_name LIKE '%mpesa%' OR account_name LIKE '%mobile money%')
            ");
            $bank_balance = (float)$stmt->fetchColumn();

            // Petty cash net (deposits − expenses) up to as_of_date
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END), 0)
                  FROM petty_cash_transactions
                 WHERE transaction_date <= ?
            ");
            $stmt->execute([$date]);
            $petty_cash = max(0.0, (float)$stmt->fetchColumn());  // floor at 0; can't have negative cash

            // POS cash register — sum of latest closed shift per register on/before date
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(latest.ending_cash), 0)
                  FROM (
                      SELECT s.register_id, s.ending_cash
                        FROM cash_register_shifts s
                       INNER JOIN (
                           SELECT register_id, MAX(shift_id) max_id
                             FROM cash_register_shifts
                            WHERE status = 'closed'
                              AND DATE(end_time) <= ?
                         GROUP BY register_id
                       ) m ON s.shift_id = m.max_id
                  ) latest
            ");
            $stmt->execute([$date]);
            $pos_cash = (float)$stmt->fetchColumn();

            $cash_bank = $bank_balance + $petty_cash + $pos_cash;
            if ($bank_balance > 0) $cash_breakdown[] = ['name' => 'Bank balances',  'amount' => $bank_balance];
            if ($petty_cash    > 0) $cash_breakdown[] = ['name' => 'Petty cash',     'amount' => $petty_cash];
            if ($pos_cash      > 0) $cash_breakdown[] = ['name' => 'Cash register',  'amount' => $pos_cash];
        }

        // Trade Receivables — invoices.balance_due, unpaid as of date.
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(balance_due), 0)
                  FROM invoices
                 WHERE status NOT IN ('paid','cancelled')
                   AND invoice_date <= ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$date], $scope['params']));
        $ar = (float)$stmt->fetchColumn();

        // Inventory — stock × cost_price. Company-wide.
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

        // ── NON-CURRENT ASSETS ─────────────────────────────────────────

        // Property, Plant & Equipment — cost minus accumulated depreciation.
        // accumulated_depreciation column exists (Phase 1) but is 0 until
        // the Phase 2 depreciation engine populates it; layout is ready.
        $ppe_cost = 0.0;
        $ppe_accumulated = 0.0;
        if ($project_id === null) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(cost), 0)
                  FROM assets
                 WHERE purchase_date <= ?
                   AND (status IS NULL OR status != 'disposed')
                   AND (disposal_date IS NULL OR disposal_date > ?)
            ");
            $stmt->execute([$date, $date]);
            $ppe_cost = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(accumulated_depreciation), 0)
                  FROM assets
                 WHERE purchase_date <= ?
                   AND (status IS NULL OR status != 'disposed')
                   AND (disposal_date IS NULL OR disposal_date > ?)
            ");
            $stmt->execute([$date, $date]);
            $ppe_accumulated = (float)$stmt->fetchColumn();
        }
        $ppe_nbv = $ppe_cost - $ppe_accumulated;

        // ── CURRENT LIABILITIES ────────────────────────────────────────

        // Trade Payables — supplier invoices approved + unpaid.
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM supplier_invoices
                 WHERE status = 'approved'
                   AND payment_date IS NULL
                   AND date_recorded <= ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$date], $scope['params']));
        $ap = (float)$stmt->fetchColumn();

        // VAT control position — summed directly from the VAT posted on live
        // documents (Output VAT, a liability = Σ invoices.output_vat_posted;
        // Input VAT, an asset = Σ supplier_invoices.input_vat_posted), so it is
        // drift-proof and always agrees with the Tax Report. Company-wide only —
        // VAT is settled with TRA at entity level, not per project. Running
        // position to date (independent of $date), same basis as cash/bank.
        $vat_output = 0.0;
        $vat_input  = 0.0;
        if ($project_id === null) {
            $vat = vatNetPosition($pdo);
            $vat_output = (float)$vat['output'];
            $vat_input  = (float)$vat['input'];
        }

        // Salaries Payable — unpaid payroll. Company-wide.
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

        // ── EQUITY ─────────────────────────────────────────────────────

        // Share Capital — from system_settings (default 0 if unset).
        $share_capital = 0.0;
        if ($project_id === null) {
            $row = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'share_capital_paid_in' LIMIT 1")->fetch();
            if ($row && is_numeric($row['setting_value'])) $share_capital = (float)$row['setting_value'];
        }

        // Opening Balance Equity — equity-typed accounts.
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

        // Current Year Net Profit — mini-IS (cash basis) from year start → date.
        $current_year_net_profit = 0.0;
        if ($yearStart !== $date) {
            // Revenue: paid invoices
            $scope = $scopeClause('project_id', '');
            $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
                      FROM invoices
                     WHERE status = 'paid'
                       AND payment_date BETWEEN ? AND ?"
                 . $scope['sql'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$yearStart, $date], $scope['params']));
            $rev = (float)$stmt->fetchColumn();

            // Revenue: paid IPCs
            $sql = "SELECT COALESCE(SUM(certified_amount), 0)
                      FROM interim_payment_certificates
                     WHERE status = 'Paid' AND invoice_id IS NULL
                       AND ipc_date BETWEEN ? AND ?"
                 . $scope['sql'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$yearStart, $date], $scope['params']));
            $rev += (float)$stmt->fetchColumn();

            // Less Sales Returns
            if ($hasSalesReturns) {
                $scope2 = $scopeClause('i.project_id', 'i');
                $sql = "SELECT COALESCE(SUM(sr.grand_total - sr.total_tax), 0)
                          FROM sales_returns sr
                     LEFT JOIN invoices i ON sr.invoice_id = i.invoice_id
                         WHERE sr.status = 'refunded'
                           AND sr.return_date BETWEEN ? AND ?"
                     . $scope2['sql'];
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$yearStart, $date], $scope2['params']));
                $rev -= (float)$stmt->fetchColumn();
            }

            // COGS Trading
            $scope2 = $scopeClause('i.project_id', 'i');
            $sql = "SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
                      FROM invoices i
                INNER JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
                INNER JOIN products p ON p.product_id = ii.product_id
                     WHERE i.status = 'paid'
                       AND i.payment_date BETWEEN ? AND ?
                       AND ii.product_id IS NOT NULL"
                 . $scope2['sql'];
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$yearStart, $date], $scope2['params']));
            $cogs = (float)$stmt->fetchColumn();

            // Project-direct expenses (COGS) and General Operating Expenses
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
            $stmt->execute(array_merge([$yearStart, $date], $scope_pd['params']));
            $cogs += (float)$stmt->fetchColumn();

            $opex = 0.0;
            if ($project_id === null) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(e.amount), 0)
                      FROM expenses e
                     WHERE e.status = 'paid'
                       AND e.payroll_id IS NULL
                       AND e.project_id IS NULL
                       AND e.expense_date BETWEEN ? AND ?
                ");
                $stmt->execute([$yearStart, $date]);
                $opex += (float)$stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(net_salary), 0)
                      FROM payroll
                     WHERE payment_status = 'paid'
                       AND payment_date BETWEEN ? AND ?
                ");
                $stmt->execute([$yearStart, $date]);
                $opex += (float)$stmt->fetchColumn();
            }

            $current_year_net_profit = $rev - $cogs - $opex;
        }

        return [
            'cash_bank'              => $cash_bank,
            'cash_breakdown'         => $cash_breakdown,
            'ar'                     => $ar,
            'inventory'              => $inventory,
            'ppe_cost'               => $ppe_cost,
            'ppe_accumulated'        => $ppe_accumulated,
            'ppe_nbv'                => $ppe_nbv,
            'ap'                     => $ap,
            'vat_output'             => $vat_output,
            'vat_input'              => $vat_input,
            'salaries_payable'       => $salaries_payable,
            'share_capital'          => $share_capital,
            'opening_equity'         => $opening_equity,
            'current_year_net_profit'=> $current_year_net_profit,
        ];
    };

    $cur = $computeAsOf($as_of_date, $current_year_start);
    $cmp = $computeAsOf($comparative_date, $prev_year_start);

    // ── Net VAT position (offset input vs output — same TRA counterparty,
    //    settled net per IAS 1 right-of-set-off). Positive → Payable (a
    //    liability); negative → Refundable (an asset). Shown on one side only.
    $cur_vat_net        = round($cur['vat_output'] - $cur['vat_input'], 2);
    $cur_vat_payable    = $cur_vat_net > 0 ? $cur_vat_net : 0.0;
    $cur_vat_refundable = $cur_vat_net < 0 ? -$cur_vat_net : 0.0;
    $cmp_vat_net        = round($cmp['vat_output'] - $cmp['vat_input'], 2);
    $cmp_vat_payable    = $cmp_vat_net > 0 ? $cmp_vat_net : 0.0;
    $cmp_vat_refundable = $cmp_vat_net < 0 ? -$cmp_vat_net : 0.0;

    // ── Section totals ──────────────────────────────────────────────────
    $cur_current_assets       = $cur['cash_bank'] + $cur['ar'] + $cur['inventory'] + $cur_vat_refundable;
    $cur_non_current_assets   = $cur['ppe_nbv'];
    $cur_total_assets         = $cur_current_assets + $cur_non_current_assets;
    $cur_current_liabilities  = $cur['ap'] + $cur_vat_payable + $cur['salaries_payable'];
    $cur_non_current_liab     = 0.0;
    $cur_total_liabilities    = $cur_current_liabilities + $cur_non_current_liab;
    // Equity (Retained Earnings is the balancing plug)
    $cur_retained_earnings    = $cur_total_assets - $cur_total_liabilities
                                - $cur['share_capital'] - $cur['opening_equity']
                                - $cur['current_year_net_profit'];
    $cur_total_equity         = $cur['share_capital'] + $cur['opening_equity']
                                + $cur_retained_earnings + $cur['current_year_net_profit'];
    $cur_liab_plus_equity     = $cur_total_liabilities + $cur_total_equity;
    $cur_balanced             = abs($cur_total_assets - $cur_liab_plus_equity) < 0.5;

    $cmp_current_assets       = $cmp['cash_bank'] + $cmp['ar'] + $cmp['inventory'] + $cmp_vat_refundable;
    $cmp_non_current_assets   = $cmp['ppe_nbv'];
    $cmp_total_assets         = $cmp_current_assets + $cmp_non_current_assets;
    $cmp_current_liabilities  = $cmp['ap'] + $cmp_vat_payable + $cmp['salaries_payable'];
    $cmp_total_liabilities    = $cmp_current_liabilities;
    $cmp_retained_earnings    = $cmp_total_assets - $cmp_total_liabilities
                                - $cmp['share_capital'] - $cmp['opening_equity']
                                - $cmp['current_year_net_profit'];
    $cmp_total_equity         = $cmp['share_capital'] + $cmp['opening_equity']
                                + $cmp_retained_earnings + $cmp['current_year_net_profit'];

    // ── Compose lines ───────────────────────────────────────────────────
    $current_assets_lines = [];
    if ($cur['cash_bank'] != 0 || $cmp['cash_bank'] != 0) {
        $current_assets_lines[] = ['name' => 'Cash & Cash Equivalents', 'amount' => $cur['cash_bank'], 'comparative_amount' => $cmp['cash_bank']];
        foreach ($cur['cash_breakdown'] as $line) {
            $current_assets_lines[] = ['name' => '  · ' . $line['name'], 'amount' => $line['amount'], 'comparative_amount' => null, 'is_breakdown' => true];
        }
    }
    if ($cur['ar'] != 0 || $cmp['ar'] != 0)
        $current_assets_lines[] = ['name' => 'Trade Receivables', 'amount' => $cur['ar'], 'comparative_amount' => $cmp['ar']];
    // VAT Recoverable (net) — only when in a net refundable position (Input > Output).
    if ($cur_vat_refundable != 0 || $cmp_vat_refundable != 0) {
        $current_assets_lines[] = ['name' => 'VAT Recoverable (net)', 'amount' => $cur_vat_refundable, 'comparative_amount' => $cmp_vat_refundable];
        $current_assets_lines[] = ['name' => '  · Input VAT (purchases)',  'amount' => $cur['vat_input'],  'comparative_amount' => null, 'is_breakdown' => true];
        $current_assets_lines[] = ['name' => '  · Less: Output VAT (sales)', 'amount' => -$cur['vat_output'], 'comparative_amount' => null, 'is_breakdown' => true];
    }
    if ($cur['inventory'] != 0 || $cmp['inventory'] != 0)
        $current_assets_lines[] = ['name' => 'Inventory', 'amount' => $cur['inventory'], 'comparative_amount' => $cmp['inventory']];

    $non_current_assets_lines = [];
    if ($cur['ppe_cost'] != 0 || $cmp['ppe_cost'] != 0) {
        $non_current_assets_lines[] = ['name' => 'Property, Plant & Equipment — at Cost', 'amount' => $cur['ppe_cost'], 'comparative_amount' => $cmp['ppe_cost']];
        $non_current_assets_lines[] = ['name' => 'Less: Accumulated Depreciation', 'amount' => -$cur['ppe_accumulated'], 'comparative_amount' => -$cmp['ppe_accumulated']];
        $non_current_assets_lines[] = ['name' => 'Net Book Value (PP&E)', 'amount' => $cur['ppe_nbv'], 'comparative_amount' => $cmp['ppe_nbv'], 'is_subtotal' => true];
    }

    $current_liabilities_lines = [];
    if ($cur['ap'] != 0 || $cmp['ap'] != 0)
        $current_liabilities_lines[] = ['name' => 'Trade Payables', 'amount' => $cur['ap'], 'comparative_amount' => $cmp['ap']];
    // VAT Payable (net) — only when in a net payable position (Output > Input).
    if ($cur_vat_payable != 0 || $cmp_vat_payable != 0) {
        $current_liabilities_lines[] = ['name' => 'VAT Payable (net)', 'amount' => $cur_vat_payable, 'comparative_amount' => $cmp_vat_payable];
        $current_liabilities_lines[] = ['name' => '  · Output VAT (sales)',       'amount' => $cur['vat_output'],  'comparative_amount' => null, 'is_breakdown' => true];
        $current_liabilities_lines[] = ['name' => '  · Less: Input VAT (purchases)', 'amount' => -$cur['vat_input'], 'comparative_amount' => null, 'is_breakdown' => true];
    }
    if ($cur['salaries_payable'] != 0 || $cmp['salaries_payable'] != 0)
        $current_liabilities_lines[] = ['name' => 'Salaries & Wages Payable', 'amount' => $cur['salaries_payable'], 'comparative_amount' => $cmp['salaries_payable']];

    $non_current_liabilities_lines = [];   // empty by design — no borrowings tracked

    $equity_lines = [];
    if ($cur['share_capital'] != 0 || $cmp['share_capital'] != 0)
        $equity_lines[] = ['name' => 'Share Capital', 'amount' => $cur['share_capital'], 'comparative_amount' => $cmp['share_capital']];
    if ($cur['opening_equity'] != 0 || $cmp['opening_equity'] != 0)
        $equity_lines[] = ['name' => 'Opening Balance Equity', 'amount' => $cur['opening_equity'], 'comparative_amount' => $cmp['opening_equity']];
    $equity_lines[] = ['name' => 'Retained Earnings (computed)', 'amount' => $cur_retained_earnings, 'comparative_amount' => $cmp_retained_earnings];
    $equity_lines[] = ['name' => 'Current Year Net Profit', 'amount' => $cur['current_year_net_profit'], 'comparative_amount' => $cmp['current_year_net_profit']];

    // ── Statement of Changes in Equity ──────────────────────────────────
    $changes_in_equity = [
        ['name' => 'Opening Equity (brought forward)',  'amount' => $cur['opening_equity']],
        ['name' => 'Add: Share Capital',                'amount' => $cur['share_capital']],
        ['name' => 'Add: Current Year Profit',          'amount' => $cur['current_year_net_profit']],
        ['name' => 'Less: Dividends paid',              'amount' => 0.0],
        ['name' => 'Retained Earnings (computed plug)', 'amount' => $cur_retained_earnings],
        ['name' => 'Closing Equity',                    'amount' => $cur_total_equity, 'is_subtotal' => true],
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'meta' => [
                'as_of_date'           => $as_of_date,
                'comparative_date'     => $comparative_date,
                'current_year_start'   => $current_year_start,
                'project_id'           => $project_id,
                'project_filter_active'=> $project_id !== null,
                'is_admin'             => $is_admin,
                'scoped_project_ids'   => $is_admin ? null : $user_project_ids,
            ],
            'sections' => [
                'current_assets'          => ['lines' => $current_assets_lines,         'total' => $cur_current_assets,     'comparative_total' => $cmp_current_assets],
                'non_current_assets'      => ['lines' => $non_current_assets_lines,     'total' => $cur_non_current_assets, 'comparative_total' => $cmp_non_current_assets],
                'current_liabilities'     => ['lines' => $current_liabilities_lines,    'total' => $cur_current_liabilities,'comparative_total' => $cmp_current_liabilities],
                'non_current_liabilities' => ['lines' => $non_current_liabilities_lines,'total' => 0.0,                     'comparative_total' => 0.0],
                'equity'                  => ['lines' => $equity_lines,                 'total' => $cur_total_equity,       'comparative_total' => $cmp_total_equity],
                'changes_in_equity'       => ['lines' => $changes_in_equity,            'opening' => $cur['opening_equity'],'closing' => $cur_total_equity],
            ],
            'totals' => [
                'total_assets'        => $cur_total_assets,
                'total_liabilities'   => $cur_total_liabilities,
                'total_equity'        => $cur_total_equity,
                'liab_plus_equity'    => $cur_liab_plus_equity,
                'balanced'            => $cur_balanced,
                'balance_difference'  => $cur_total_assets - $cur_liab_plus_equity,
                'comparative'         => [
                    'total_assets'        => $cmp_total_assets,
                    'total_liabilities'   => $cmp_total_liabilities,
                    'total_equity'        => $cmp_total_equity,
                ],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('Balance Sheet API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
