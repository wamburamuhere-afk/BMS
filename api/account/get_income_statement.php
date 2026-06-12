<?php
/**
 * Income Statement (Profit & Loss) — Data API
 *
 * Operational + manual-journals hybrid implementation per the agreed plan.
 *
 * Accrual basis: revenue + product COGS are recognised once an invoice is
 * APPROVED (status IN approved/paid/partial) — draft states are excluded,
 * mirroring a posted-only ledger.
 *
 * REVENUE
 *   Sales of Goods & Services         = invoices (status approved/paid/partial), (grand_total − tax_amount), invoice_date in period
 *   POS / Counter Sales               = pos_sales (sale_status completed/partially_refunded/refunded), (grand_total − tax_amount),
 *                                       sale_date in period — only sales with invoice_id IS NULL and is_return_sale=0
 *                                       (avoids double-count with linked invoice; gross kept even when refunded)
 *   Less: POS Returns                 = pos_sales return rows (is_return_sale=1), (grand_total − tax_amount) — contra-revenue
 *   Contract Revenue (IPCs)           = interim_payment_certificates.Paid (certified_amount)
 *                                       — only IPCs with invoice_id IS NULL (avoids double-count with linked invoice)
 *   Less: Sales Returns               = sales_returns.refunded (grand_total − total_tax)
 *   + Manual Revenue Journals         = journal_entries.posted on revenue-category accounts
 *
 * COGS (Path B: product cost + project direct cost)
 *   Cost of Goods Sold (Trading)      = SUM(invoice_items.quantity × products.cost_price)
 *                                       for recognised invoices (approved/paid/partial) with product_id IS NOT NULL
 *   Cost of Goods Sold (POS/Counter)  = SUM(pos_sale_items.quantity × products.cost_price)
 *                                       for the same recognised POS sales (matches the POS revenue line)
 *   Sub-contractor Costs              = supplier_invoices (invoice_type='sub_contractor', status approved/paid)
 *   Project Direct Costs              = expenses.paid where project_id IS NOT NULL,
 *                                       grouped by expense_categories.name, payroll_id IS NULL
 *   + Manual COGS Journals            = journal_entries.posted on cogs-category accounts
 *
 * OPERATING EXPENSES (accrual — recognised when incurred)
 *   General Expenses                  = expenses (status approved/paid) where project_id IS NULL, payroll_id IS NULL,
 *                                       grouped by expense_categories.name
 *   Compensation (Salaries)           = payroll where payment_status='paid' (net_salary)
 *                                       — still cash-trigger; unpaid payroll is disclosed via a warning banner
 *   Depreciation & Amortisation       = asset_depreciation_runs not yet linked to a journal entry
 *   + Manual Expense Journals         = journal_entries.posted on expense-category accounts
 *
 * OTHER INCOME / FINANCE COSTS / INCOME TAX
 *   Other Income                      = supplier_credit_notes (approved/applied)
 *   Finance Costs                     = journal_entries.posted on finance_cost-category accounts
 *   Income Tax                        = posted journal entries to the configured income-tax account
 *                                       (system_settings.default_income_tax_account_id); 0 when unset
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
require_once __DIR__ . '/../../core/payroll_tax.php';   // sdlRate(), sdlMinEmployees(), calcSdlAmount()

// Guarded: consumed as an internal report partial after headers are sent.
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Guard: the account_types classification columns must exist on this server
// (migration 2026_05_27). Without them every at.category query throws; return a
// clear message the page can show instead of a raw SQL error.
if (!fc_classification_ready($pdo)) {
    echo json_encode(['success' => false, 'message' =>
        'Income Statement is not available: the account-type classification has not been installed on this server. '
      . 'An administrator should run the pending migration 2026_05_27_account_types_classification.php (see /migrations/status.php).']);
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
// Uses the canonical userCan() helper from core/project_scope.php (loaded via
// core/permissions.php) — same semantics as the old manual in_array check.
if ($project_id !== null && !userCan('project', $project_id)) {
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
    //
    // Specific project requested → "AND <col> = ?" (authorization for
    //   non-admins was already verified via userCan() above).
    //
    // No specific project → delegates to the canonical scopeFilterSqlNullable()
    //   helper from core/project_scope.php so we behave exactly like every
    //   other scoped list/detail page in BMS:
    //     · admin                                    → '' (no filter)
    //     · non-admin with assignments               → 'AND (col IS NULL OR col IN (...))'
    //     · non-admin with no assignments            → 'AND 0' (default deny)
    //
    // The "OR IS NULL" is the smart twist the user wanted: project managers
    // see THEIR projects' direct results plus their share of company-wide
    // overhead (rows where project_id is null) in the consolidated view.
    // ───────────────────────────────────────────────────────────────────────
    $scopeClause = function (string $col, string $alias = '') use ($project_id): array {
        if ($project_id !== null) {
            return ['sql' => " AND $col = ?", 'params' => [$project_id]];
        }
        // scopeFilterSqlNullable already returns " AND (alias.project_id IS NULL
        // OR alias.project_id IN (...)) " for non-admins; '' for admins; ' AND 0 '
        // for non-admin-no-assignments.
        return [
            'sql'    => scopeFilterSqlNullable('project', $alias),
            'params' => [],
        ];
    };

    // ───────────────────────────────────────────────────────────────────────
    // Helper closures — each returns scalars/rows for the requested window
    // ───────────────────────────────────────────────────────────────────────

    /**
     * All invoices' net revenue (grand_total - tax_amount) in window,
     * all statuses included, filtered by invoice_date.
     */
    $sumSales = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('project_id', '');
        // Accrual basis: recognise revenue once an invoice is APPROVED. Exclude
        // draft states (pending/reviewed) that are not yet recognised, mirroring
        // a posted-only general ledger.
        // Recognition: every status except cancelled/rejected/deleted/draft
        // (agreed scope). Unpaid balances are carried on the Balance Sheet as AR.
        $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
                  FROM invoices
                 WHERE invoice_date BETWEEN ? AND ?
                   AND status NOT IN ('cancelled','rejected','deleted','draft')"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Sum of Paid IPCs' certified_amount (excluding those linked to an invoice).
     */
    $sumIPC = function (string $from, string $to) use ($pdo, $scopeClause): float {
        $scope = $scopeClause('project_id', '');
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
     * Project filter resolved via JOIN on invoices.project_id. Guarded so
     * servers that don't yet have the sales_returns table degrade to 0
     * silently rather than 500.
     */
    $sumSalesReturns = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_returns'")->fetch();
        } catch (Throwable $e) {
            $tableExists = false;
        }
        if (!$tableExists) return 0.0;

        $scope = $scopeClause('i.project_id', 'i');
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
     * Sum of PAID credit notes' net amount (grand_total - total_tax). A credit
     * note is the standalone refund document issued to a customer; once paid it
     * carries the refund recognition (the originating sales return stays
     * 'approved', so it is NOT also counted by $sumSalesReturns — no double
     * count). Scoped via the linked sales order's project; standalone notes
     * (no order) are company-wide. Degrades to 0 when the table is absent.
     */
    $sumCreditNotes = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE 'credit_notes'")->fetch();
        } catch (Throwable $e) {
            $exists = false;
        }
        if (!$exists) return 0.0;

        $scope = $scopeClause('so.project_id', 'so');
        $sql = "SELECT COALESCE(SUM(cn.grand_total - cn.total_tax), 0)
                  FROM credit_notes cn
             LEFT JOIN sales_returns sr ON cn.sales_return_id = sr.sales_return_id
             LEFT JOIN sales_orders so  ON sr.sales_order_id  = so.sales_order_id
                 WHERE cn.status = 'paid'
                   AND cn.credit_date BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * POS / Counter Sales — net revenue (grand_total − tax_amount) from the
     * dedicated Point-of-Sale module (pos_sales). These never reach the
     * `invoices` table, so without this they were missing from the P&L entirely.
     *
     * Recognition mirrors the invoice rule:
     *   - sale_status IN ('completed','partially_refunded','refunded')
     *       — every original sale that genuinely happened keeps its GROSS revenue;
     *         goods returned later are subtracted separately as contra (see
     *         $sumPosReturns), so a fully-refunded sale nets to zero without being
     *         double-subtracted. Voided/cancelled/draft/pending/on_hold are excluded
     *         (a voided sale is treated as if it never happened).
     *   - invoice_id IS NULL    (a POS sale already converted to an invoice is
     *       counted via $sumSales — avoid double count)
     *   - is_return_sale = 0    (return rows are the contra, never gross revenue)
     *   - by DATE(sale_date) so the datetime column respects the period boundaries
     * Project-scoped via pos_sales.project_id. Degrades to 0 when the table is
     * absent (older servers without the POS module).
     */
    $sumPosSales = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) return 0.0;
        } catch (Throwable $e) { return 0.0; }
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
                  FROM pos_sales
                 WHERE sale_status IN ('completed','partially_refunded','refunded')
                   AND invoice_id IS NULL
                   AND is_return_sale = 0
                   AND DATE(sale_date) BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * POS Returns — contra-revenue. Net value (grand_total − tax_amount) of POS
     * RETURN rows (is_return_sale = 1) created by api/pos/create_return.php. The
     * original sale keeps its gross revenue above; this subtracts the returned
     * portion, so net POS revenue = gross − returns. Project-scoped; degrades to 0.
     */
    $sumPosReturns = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) return 0.0;
        } catch (Throwable $e) { return 0.0; }
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(grand_total - tax_amount), 0)
                  FROM pos_sales
                 WHERE is_return_sale = 1
                   AND sale_status NOT IN ('voided','cancelled')
                   AND invoice_id IS NULL
                   AND DATE(sale_date) BETWEEN ? AND ?"
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
        $scope = $scopeClause('i.project_id', 'i');
        // Matching principle: recognise COGS with the revenue it relates to —
        // same recognised-status set as $sumSales (approved/paid/partial).
        $sql = "SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
                  FROM invoices i
            INNER JOIN invoice_items ii ON ii.invoice_id = i.invoice_id
            INNER JOIN products p       ON p.product_id  = ii.product_id
                 WHERE i.invoice_date BETWEEN ? AND ?
                   AND i.status NOT IN ('cancelled','rejected','deleted','draft')
                   AND ii.product_id IS NOT NULL"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * COGS — POS / Counter product cost, NET of returns. SUM(pos_sale_items.quantity
     * × products.cost_price) for sale lines, MINUS the same for return lines (the
     * goods come back into inventory when returned). One signed query keeps the
     * figure consistent with $sumPosSales − $sumPosReturns, so gross profit stays
     * honest. Same recognition statuses + project scope as the revenue side.
     * Degrades to 0 when the POS tables are absent.
     */
    $sumPosCOGS = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'pos_sale_items'")->fetch()) return 0.0;
        } catch (Throwable $e) { return 0.0; }
        $scope = $scopeClause('ps.project_id', 'ps');
        $sql = "SELECT COALESCE(SUM(
                          CASE WHEN ps.is_return_sale = 0
                               THEN  psi.quantity * COALESCE(p.cost_price, 0)
                               ELSE -psi.quantity * COALESCE(p.cost_price, 0) END), 0)
                  FROM pos_sales ps
            INNER JOIN pos_sale_items psi ON psi.sale_id = ps.sale_id
            INNER JOIN products p         ON p.product_id = psi.product_id
                 WHERE ps.invoice_id IS NULL
                   AND psi.product_id IS NOT NULL
                   AND ( (ps.is_return_sale = 0 AND ps.sale_status IN ('completed','partially_refunded','refunded'))
                      OR (ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled')) )
                   AND DATE(ps.sale_date) BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Sub-contractor costs from supplier_invoices where invoice_type = 'sub_contractor'.
     * These are direct project costs — work is consumed immediately, no inventory involved.
     * Counted when status = 'approved' or 'paid' (accrual basis), by date_raised.
     */
    $sumSubcontractorCosts = function (string $from, string $to) use ($pdo, $scopeClause, $project_id): float {
        try {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE 'supplier_invoices'")->fetch();
        } catch (Throwable $e) { $exists = false; }
        if (!$exists) return 0.0;

        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM supplier_invoices
                 WHERE invoice_type = 'sub_contractor'
                   AND status NOT IN ('cancelled','rejected','deleted','draft')
                   AND date_raised BETWEEN ? AND ?"
             . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$from, $to], $scope['params']));
        return (float) $stmt->fetchColumn();
    };

    /**
     * Depreciation & Amortisation from asset_depreciation_runs.
     * Only pulls runs NOT yet linked to a journal entry (journal_entry_id IS NULL)
     * to avoid double-counting with journal-based expense entries.
     */
    $sumDepreciation = function (string $from, string $to) use ($pdo): float {
        try {
            $exists = (bool)$pdo->query("SHOW TABLES LIKE 'asset_depreciation_runs'")->fetch();
        } catch (Throwable $e) { $exists = false; }
        if (!$exists) return 0.0;
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(period_amount), 0)
              FROM asset_depreciation_runs
             WHERE period_end_date BETWEEN ? AND ?
               AND journal_entry_id IS NULL
        ");
        $stmt->execute([$from, $to]);
        return (float) $stmt->fetchColumn();
    };

    /**
     * Other Income — supplier credit notes / paid debit notes in the period.
     * These are amounts a supplier credits/refunds to the business: income.
     * Two sources are summed (either may be absent on a given server):
     *   - legacy `supplier_credit_notes` placeholder table (approved/applied), and
     *   - the new `debit_notes` document once PAID (net of VAT).
     */
    $sumOtherIncome = function (string $from, string $to) use ($pdo): float {
        $total = 0.0;
        // Legacy placeholder table (usually absent → contributes 0).
        try {
            if ($pdo->query("SHOW TABLES LIKE 'supplier_credit_notes'")->fetch()) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                      FROM supplier_credit_notes
                     WHERE status IN ('approved','applied')
                       AND credit_date BETWEEN ? AND ?
                ");
                $stmt->execute([$from, $to]);
                $total += (float) $stmt->fetchColumn();
            }
        } catch (Throwable $e) { /* degrade to 0 */ }
        // Paid debit notes (supplier refunds we received) — net of VAT.
        try {
            if ($pdo->query("SHOW TABLES LIKE 'debit_notes'")->fetch()) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(grand_total - total_tax), 0)
                      FROM debit_notes
                     WHERE status = 'paid'
                       AND debit_date BETWEEN ? AND ?
                ");
                $stmt->execute([$from, $to]);
                $total += (float) $stmt->fetchColumn();
            }
        } catch (Throwable $e) { /* degrade to 0 */ }
        return $total;
    };

    /**
     * Standalone Revenue / Other Income (Plan 3) — posted `revenues` in the period.
     * Non-sales income (interest, grants, asset disposal, misc) recorded via the
     * Revenue module and POSTED. Project-scoped like the other revenue lines.
     * Degrades to 0 when the table is absent (no regression for older servers).
     */
    $sumStandaloneRevenue = function (string $from, string $to) use ($pdo, $scopeClause): float {
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'revenues'")->fetch()) return 0.0;
        } catch (Throwable $e) { return 0.0; }
        $scope = $scopeClause('project_id', '');
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM revenues
                 WHERE status = 'posted'
                   AND revenue_date BETWEEN ? AND ?"
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
     *
     * Accrual basis: recognises expenses once INCURRED (status approved or paid),
     * not only when cash-settled — matching the accrual revenue recognition.
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
                ec.id AS category_id,
                COALESCE(SUM(CASE WHEN e.expense_date BETWEEN ? AND ? THEN e.amount ELSE 0 END), 0) AS current_period,
                COALESCE(SUM(CASE WHEN e.expense_date BETWEEN ? AND ? THEN e.amount ELSE 0 END), 0) AS previous_period
              FROM expenses e
         LEFT JOIN expense_categories ec ON e.category_id = ec.id
             WHERE e.status NOT IN ('cancelled','rejected','deleted','draft')
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
        // Accrual: recognise payroll for the period regardless of payment, by the
        // payroll date (cancelled/rejected excluded). GROSS is the true cost of
        // employment — PAYE & NSSF withheld are part of that cost (remitted to
        // TRA/NSSF, not kept), so they are NOT netted out of the expense; they sit
        // as liabilities on the Balance Sheet (Salaries / PAYE / NSSF Payable).
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(gross_salary), 0)
              FROM payroll
             WHERE payment_status NOT IN ('cancelled','rejected')
               AND COALESCE(payroll_date, STR_TO_DATE(CONCAT(payroll_period,'-01'),'%Y-%m-%d')) BETWEEN ? AND ?
        ");
        $stmt->execute([$from, $to]);
        return (float) $stmt->fetchColumn();
    };

    /**
     * Employer Skills Development Levy (SDL) for the period — an EMPLOYER expense
     * (not deducted from staff): rate% of total gross payroll, only when the
     * company has at least the statutory minimum employees. Company-wide, so it is
     * suppressed under a specific-project view (it is not project-attributable).
     */
    $sumSdlExpense = function (string $from, string $to) use ($pdo, $project_id): float {
        if ($project_id !== null) return 0.0;
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(gross_salary),0) AS gross, COUNT(DISTINCT employee_id) AS cnt
              FROM payroll
             WHERE payment_status NOT IN ('cancelled','rejected')
               AND COALESCE(payroll_date, STR_TO_DATE(CONCAT(payroll_period,'-01'),'%Y-%m-%d')) BETWEEN ? AND ?
        ");
        $stmt->execute([$from, $to]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['gross' => 0, 'cnt' => 0];
        return calcSdlAmount((float)$r['gross'], sdlRate($pdo), (int)$r['cnt'], sdlMinEmployees($pdo));
    };

    /**
     * Petty Cash expenses for the period — disbursements recorded in the dedicated
     * petty_cash_transactions module (type='expense'). These never reach the
     * `expenses` table, so without this they were missing from the P&L. Company-wide
     * (no project tag) → suppressed under a specific-project view. Degrades to 0 if
     * the table is absent.
     */
    $sumPettyCash = function (string $from, string $to) use ($pdo, $project_id): float {
        if ($project_id !== null) return 0.0;
        try {
            if (!$pdo->query("SHOW TABLES LIKE 'petty_cash_transactions'")->fetch()) return 0.0;
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                  FROM petty_cash_transactions
                 WHERE type = 'expense'
                   AND transaction_date BETWEEN ? AND ?
            ");
            $stmt->execute([$from, $to]);
            return (float) $stmt->fetchColumn();
        } catch (Throwable $e) { return 0.0; }
    };

    /**
     * Income tax provision for the period — posted journal entries to the
     * configured income-tax account (system_settings.default_income_tax_account_id).
     * Tax is a debit-normal expense, so (debits − credits) gives the charge.
     * Returns 0 when no account is configured (no regression for servers that
     * haven't set one) — the accountant posts the provision via Journal Entries.
     */
    $sumIncomeTax = function (string $from, string $to) use ($pdo): float {
        $acc = (int) getSetting('default_income_tax_account_id', 0);
        if ($acc <= 0) return 0.0;
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount
                                     WHEN jei.type = 'credit' THEN -jei.amount
                                     ELSE 0 END), 0)
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id
             WHERE jei.account_id = ?
               AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
        ");
        $stmt->execute([$acc, $from, $to]);
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
                'drill'        => ['source' => 'journal', 'account_id' => (int)$r['account_id']],
            ];
        }
        return $out;
    };

    // ───────────────────────────────────────────────────────────────────────
    // REVENUE SECTION
    // ───────────────────────────────────────────────────────────────────────
    $rev_sales_cur         = $sumSales($start_date, $end_date);
    $rev_sales_prv         = $sumSales($prev_start_date, $prev_end_date);
    $rev_ipc_cur           = $sumIPC($start_date, $end_date);
    $rev_ipc_prv           = $sumIPC($prev_start_date, $prev_end_date);
    $rev_pos_cur           = $sumPosSales($start_date, $end_date);
    $rev_pos_prv           = $sumPosSales($prev_start_date, $prev_end_date);
    $pos_ret_cur           = $sumPosReturns($start_date, $end_date);
    $pos_ret_prv           = $sumPosReturns($prev_start_date, $prev_end_date);
    $sales_returns_current = $sumSalesReturns($start_date, $end_date);
    $sales_returns_previous = $sumSalesReturns($prev_start_date, $prev_end_date);
    // Paid credit notes carry the same contra-revenue treatment as refunded
    // sales returns and are added to the same line (no double count — see
    // $sumCreditNotes). A return that spawned a credit note stays 'approved'.
    $credit_notes_current  = $sumCreditNotes($start_date, $end_date);
    $credit_notes_previous = $sumCreditNotes($prev_start_date, $prev_end_date);
    // Backward-compat aliases used elsewhere in this file (and matched by tests).
    $sales_ret_cur = $sales_returns_current + $credit_notes_current;
    $sales_ret_prv = $sales_returns_previous + $credit_notes_previous;

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
            'drill'        => ['source' => 'invoices'],
        ];
    }
    if (abs($rev_ipc_cur) > 0.001 || abs($rev_ipc_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Contract Revenue (IPCs)',
            'current'      => $rev_ipc_cur,
            'previous'     => $rev_ipc_prv,
            'drill'        => ['source' => 'ipc'],
        ];
    }
    if (abs($rev_pos_cur) > 0.001 || abs($rev_pos_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'POS / Counter Sales',
            'current'      => $rev_pos_cur,
            'previous'     => $rev_pos_prv,
            'drill'        => ['source' => 'pos_sales'],
        ];
    }
    if (abs($pos_ret_cur) > 0.001 || abs($pos_ret_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Less: POS Returns',
            'current'      => -$pos_ret_cur,
            'previous'     => -$pos_ret_prv,
            'drill'        => ['source' => 'pos_returns'],
        ];
    }
    if (abs($sales_ret_cur) > 0.001 || abs($sales_ret_prv) > 0.001) {
        $revenue_lines[] = [
            'account_code' => '',
            'account_name' => 'Less: Sales Returns & Credit Notes',
            'current'      => -$sales_ret_cur,
            'previous'     => -$sales_ret_prv,
            'drill'        => ['source' => 'sales_returns'],
        ];
    }
    $revenue_lines = array_merge($revenue_lines, $revenue_journals);

    $journal_rev_cur = array_sum(array_column($revenue_journals, 'current'));
    $journal_rev_prv = array_sum(array_column($revenue_journals, 'previous'));

    $total_revenue_cur = $rev_sales_cur + $rev_ipc_cur + $rev_pos_cur - $pos_ret_cur - $sales_ret_cur + $journal_rev_cur;
    $total_revenue_prv = $rev_sales_prv + $rev_ipc_prv + $rev_pos_prv - $pos_ret_prv - $sales_ret_prv + $journal_rev_prv;

    // ───────────────────────────────────────────────────────────────────────
    // COGS SECTION (Path B: product cost + project direct cost)
    // ───────────────────────────────────────────────────────────────────────
    $cogs_prod_cur     = $sumProductCOGS($start_date, $end_date);
    $cogs_prod_prv     = $sumProductCOGS($prev_start_date, $prev_end_date);

    $cogs_pos_cur      = $sumPosCOGS($start_date, $end_date);
    $cogs_pos_prv      = $sumPosCOGS($prev_start_date, $prev_end_date);

    $cogs_subcon_cur   = $sumSubcontractorCosts($start_date, $end_date);
    $cogs_subcon_prv   = $sumSubcontractorCosts($prev_start_date, $prev_end_date);

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
            'drill'        => ['source' => 'product_cogs'],
        ];
    }
    if (abs($cogs_pos_cur) > 0.001 || abs($cogs_pos_prv) > 0.001) {
        $cogs_lines[] = [
            'account_code' => '',
            'account_name' => 'Cost of Goods Sold (POS / Counter)',
            'current'      => $cogs_pos_cur,
            'previous'     => $cogs_pos_prv,
            'drill'        => ['source' => 'pos_cogs'],
        ];
    }
    if (abs($cogs_subcon_cur) > 0.001 || abs($cogs_subcon_prv) > 0.001) {
        $cogs_lines[] = [
            'account_code' => '',
            'account_name' => 'Sub-contractor Costs',
            'current'      => $cogs_subcon_cur,
            'previous'     => $cogs_subcon_prv,
            'drill'        => ['source' => 'subcontractor'],
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
            'drill'        => ['source' => 'expenses', 'mode' => 'project_direct', 'category_id' => isset($r['category_id']) ? (int)$r['category_id'] : null],
        ];
        $cogs_proj_cur += (float)$r['current_period'];
        $cogs_proj_prv += (float)$r['previous_period'];
    }
    $cogs_lines = array_merge($cogs_lines, $cogs_journals);

    $journal_cogs_cur = array_sum(array_column($cogs_journals, 'current'));
    $journal_cogs_prv = array_sum(array_column($cogs_journals, 'previous'));

    $total_cogs_cur = $cogs_prod_cur + $cogs_pos_cur + $cogs_subcon_cur + $cogs_proj_cur + $journal_cogs_cur;
    $total_cogs_prv = $cogs_prod_prv + $cogs_pos_prv + $cogs_subcon_prv + $cogs_proj_prv + $journal_cogs_prv;

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

    $depreciation_cur  = $sumDepreciation($start_date, $end_date);
    $depreciation_prv  = $sumDepreciation($prev_start_date, $prev_end_date);

    $pettycash_cur     = $sumPettyCash($start_date, $end_date);
    $pettycash_prv     = $sumPettyCash($prev_start_date, $prev_end_date);

    $sdl_cur           = $sumSdlExpense($start_date, $end_date);
    $sdl_prv           = $sumSdlExpense($prev_start_date, $prev_end_date);

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
            'drill'        => ['source' => 'expenses', 'mode' => 'general', 'category_id' => isset($r['category_id']) ? (int)$r['category_id'] : null],
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
            'drill'        => ['source' => 'payroll'],
        ];
    }
    if (abs($depreciation_cur) > 0.001 || abs($depreciation_prv) > 0.001) {
        $opex_lines[] = [
            'account_code' => '',
            'account_name' => 'Depreciation & Amortisation',
            'current'      => $depreciation_cur,
            'previous'     => $depreciation_prv,
            'drill'        => ['source' => 'depreciation'],
        ];
    }
    // Employer Skills Development Levy — derived (rate% of gross), so no drill list.
    if (abs($sdl_cur) > 0.001 || abs($sdl_prv) > 0.001) {
        $opex_lines[] = [
            'account_code' => '',
            'account_name' => 'Skills Development Levy (SDL) — employer',
            'current'      => $sdl_cur,
            'previous'     => $sdl_prv,
        ];
    }
    // Petty cash disbursements (own module) — drillable to the individual records.
    if (abs($pettycash_cur) > 0.001 || abs($pettycash_prv) > 0.001) {
        $opex_lines[] = [
            'account_code' => '',
            'account_name' => 'Petty Cash Expenses',
            'current'      => $pettycash_cur,
            'previous'     => $pettycash_prv,
            'drill'        => ['source' => 'petty_cash'],
        ];
    }
    $opex_lines = array_merge($opex_lines, $expense_journals);

    $journal_exp_cur = array_sum(array_column($expense_journals, 'current'));
    $journal_exp_prv = array_sum(array_column($expense_journals, 'previous'));

    $total_expenses_cur = $opex_general_cur + $compensation_cur + $depreciation_cur + $sdl_cur + $pettycash_cur + $journal_exp_cur;
    $total_expenses_prv = $opex_general_prv + $compensation_prv + $depreciation_prv + $sdl_prv + $pettycash_prv + $journal_exp_prv;

    // ───────────────────────────────────────────────────────────────────────
    // OTHER INCOME SECTION (non-operating income — below EBIT per IAS 1)
    // ───────────────────────────────────────────────────────────────────────
    $other_income_cn_cur = $sumOtherIncome($start_date, $end_date);
    $other_income_cn_prv = $sumOtherIncome($prev_start_date, $prev_end_date);
    $standalone_rev_cur  = $sumStandaloneRevenue($start_date, $end_date);
    $standalone_rev_prv  = $sumStandaloneRevenue($prev_start_date, $prev_end_date);

    $other_income_cur = $other_income_cn_cur + $standalone_rev_cur;
    $other_income_prv = $other_income_cn_prv + $standalone_rev_prv;

    $other_income_lines = [];
    if (abs($other_income_cn_cur) > 0.001 || abs($other_income_cn_prv) > 0.001) {
        $other_income_lines[] = [
            'account_code' => '',
            'account_name' => 'Supplier Credit Notes',
            'current'      => $other_income_cn_cur,
            'previous'     => $other_income_cn_prv,
            'drill'        => ['source' => 'other_income'],
        ];
    }
    if (abs($standalone_rev_cur) > 0.001 || abs($standalone_rev_prv) > 0.001) {
        $other_income_lines[] = [
            'account_code' => '',
            'account_name' => 'Other Income (Revenues)',
            'current'      => $standalone_rev_cur,
            'previous'     => $standalone_rev_prv,
            'drill'        => ['source' => 'revenues'],
        ];
    }

    // ───────────────────────────────────────────────────────────────────────
    // FINANCE COSTS SECTION (below EBIT per IAS 1 — required on face of P&L)
    // Source: journal entries posted to accounts classified as 'finance_cost'.
    // Classify accounts via Settings → Account Types.
    // ───────────────────────────────────────────────────────────────────────
    $finance_type_ids  = fc_type_ids_for_categories($pdo, ['finance_cost']);
    $finance_journals  = $journalLines($finance_type_ids, 'debit',
        $start_date, $end_date, $prev_start_date, $prev_end_date);

    $finance_cost_cur = array_sum(array_column($finance_journals, 'current'));
    $finance_cost_prv = array_sum(array_column($finance_journals, 'previous'));

    // ───────────────────────────────────────────────────────────────────────
    // TOTALS & RATIOS — variable names match the professional-layout
    // contract enforced by tests/test_income_statement_cli.php:
    //   $gp  = gross profit, $te = total expenses, $tr = total revenue,
    //   $tc  = total cogs,   $operating_profit = gp - te,
    //   $income_tax, $profit_before_tax, $np = pbt - income_tax
    // ───────────────────────────────────────────────────────────────────────
    $tr  = $total_revenue_cur;
    $tc  = $total_cogs_cur;
    $te  = $total_expenses_cur;

    $gp                = $tr - $tc;
    $operating_profit  = $gp - $te;                                           // EBIT
    $income_tax        = $sumIncomeTax($start_date, $end_date);               // 0 unless an income-tax account is configured
    $profit_before_tax = $operating_profit + $other_income_cur - $finance_cost_cur;
    $np                = $profit_before_tax - $income_tax;

    // Backward-compat aliases used by the response composition below.
    $gp_cur  = $gp;
    $op_cur  = $operating_profit;
    $pbt_cur = $profit_before_tax;
    $np_cur  = $np;
    $tax_cur = $income_tax;

    // Previous-period parallels
    $gp_prv  = $total_revenue_prv - $total_cogs_prv;
    $op_prv  = $gp_prv - $total_expenses_prv;
    $tax_prv = $sumIncomeTax($prev_start_date, $prev_end_date);
    $pbt_prv = $op_prv + $other_income_prv - $finance_cost_prv;
    $np_prv  = $pbt_prv - $tax_prv;

    $gpm = $tr > 0.001 ? round(($gp                / $tr) * 100, 1) : 0.0;
    $opm = $tr > 0.001 ? round(($operating_profit  / $tr) * 100, 1) : 0.0;
    $npm = $tr > 0.001 ? round(($np                / $tr) * 100, 1) : 0.0;

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
                'other_income' => [
                    'lines'          => $other_income_lines,
                    'total_current'  => $other_income_cur,
                    'total_previous' => $other_income_prv,
                ],
                'finance_costs' => [
                    'lines'          => $finance_journals,
                    'total_current'  => $finance_cost_cur,
                    'total_previous' => $finance_cost_prv,
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
                'other_income'         => $other_income_cur,
                'finance_costs'        => $finance_cost_cur,
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
                    'other_income'      => $other_income_prv,
                    'finance_costs'     => $finance_cost_prv,
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
