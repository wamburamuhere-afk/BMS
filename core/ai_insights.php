<?php
/**
 * core/ai_insights.php
 * --------------------
 * The ONLY way the AI Assistant can read business data. A fixed registry of
 * read-only, parameterised aggregate functions. The model may choose a function
 * + args; BMS runs it and returns a small result for the model to phrase. The
 * model never sees raw rows, never writes SQL, and can never modify anything.
 *
 * Scope-aware: every function applies the same project and shop/warehouse scope
 * as the matching report, so a scoped user's answers cover only their data.
 * Sales = invoices + POS sales (as the Sales report); profit, expenses and cash
 * come from the posted ledger via core/financial_reports.php (as the statements).
 *
 * Public API:
 *   aiInsightCatalog(): array         — catalog (name, description, params) for the prompt
 *   aiRunInsight(string, array): array — ['ok'=>bool,'data'=>mixed,'error'=>?string]
 */

if (file_exists(__DIR__ . '/project_scope.php')) require_once __DIR__ . '/project_scope.php';
if (file_exists(__DIR__ . '/ai_help.php')) require_once __DIR__ . '/ai_help.php';
require_once __DIR__ . '/financial_reports.php';

if (!function_exists('_aiScope')) {
    /** Project-scope clause for an aliased table, or '' (admin/CLI/no-scope). */
    function _aiScope(string $alias): string
    {
        if (function_exists('scopeFilterSqlNullable') && !empty($_SESSION['user_id'])) {
            try { return (string)scopeFilterSqlNullable('project', $alias); } catch (Throwable $e) { return ''; }
        }
        return '';
    }
}

if (!function_exists('_aiWhScope')) {
    /** Shop/warehouse-scope clause for an aliased table with a nullable warehouse_id, or '' (admin/CLI). */
    function _aiWhScope(string $alias, bool $strict = false): string
    {
        if (function_exists('scopeFilterSqlNullable') && !empty($_SESSION['user_id'])) {
            try { return (string)($strict ? scopeFilterSql('warehouse', $alias) : scopeFilterSqlNullable('warehouse', $alias)); }
            catch (Throwable $e) { return ' AND 0 '; }
        }
        return '';
    }
}

if (!function_exists('_aiJeScope')) {
    /** Project + shop scope on the ledger header (alias je), as the reports apply it. */
    function _aiJeScope(): string { return _aiScope('je') . _aiWhScope('je'); }
}

if (!function_exists('_aiPosPaidExpr')) {
    /** Amount paid on a POS sale (alias ps) — same expression as the Sales report. */
    function _aiPosPaidExpr(PDO $pdo): string
    {
        static $expr = null;
        if ($expr === null) {
            $has = false;
            try { $has = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetchColumn(); } catch (Throwable $e) {}
            $expr = $has
                ? "COALESCE((SELECT SUM(psp.amount) FROM pos_sale_payments psp WHERE psp.sale_id = ps.sale_id), 0)"
                : "CASE WHEN ps.payment_status = 'paid' THEN ps.grand_total ELSE 0 END";
        }
        return $expr;
    }
}

if (!function_exists('_aiSalesRows')) {
    /**
     * Invoices + completed POS sales in [$f,$t] as one row set (d, cid, amt, src),
     * scoped like the Sales report. Returns [sql, params].
     */
    function _aiSalesRows(string $f, string $t): array
    {
        $inv = "SELECT i.invoice_date AS d, i.customer_id AS cid, i.grand_total AS amt, 'invoice' AS src
                  FROM invoices i
                 WHERE i.invoice_date BETWEEN ? AND ? AND i.status NOT IN ('cancelled', 'deleted')" . _aiScope('i') . _aiWhScope('i');
        $pos = "SELECT DATE(ps.sale_date) AS d, ps.customer_id AS cid, ps.grand_total AS amt, 'pos' AS src
                  FROM pos_sales ps
                 WHERE DATE(ps.sale_date) BETWEEN ? AND ? AND ps.sale_status = 'completed'" . _aiScope('ps') . _aiWhScope('ps');
        return ["$inv UNION ALL $pos", [$f, $t, $f, $t]];
    }
}

if (!function_exists('_aiBalances')) {
    /** Unpaid balances per row: invoices and POS credit sales (cid, owed, src, due), scoped. Returns sql. */
    function _aiBalances(PDO $pdo): string
    {
        $paid = _aiPosPaidExpr($pdo);
        return "SELECT i.customer_id AS cid, i.balance_due AS owed, 'invoice' AS src
                  FROM invoices i
                 WHERE i.status NOT IN ('cancelled', 'deleted') AND i.balance_due > 0" . _aiScope('i') . _aiWhScope('i') . "
                UNION ALL
                SELECT x.cid, x.owed, 'pos' FROM (
                    SELECT ps.customer_id AS cid, ps.grand_total - ($paid) AS owed
                      FROM pos_sales ps
                     WHERE ps.sale_status = 'completed'" . _aiScope('ps') . _aiWhScope('ps') . "
                ) x WHERE x.owed > 0.005";
    }
}

if (!function_exists('_aiPeriod')) {
    /** Resolve a period word OR explicit from/to into [from,to] (Y-m-d). */
    function _aiPeriod(array $a): array
    {
        $today = date('Y-m-d');
        if (!empty($a['from']) && !empty($a['to'])) return [$a['from'], $a['to']];
        switch ($a['period'] ?? 'this_month') {
            case 'today':        return [$today, $today];
            case 'yesterday':    return [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))];
            case 'last_7_days':  return [date('Y-m-d', strtotime('-6 days')), $today];
            case 'last_30_days': return [date('Y-m-d', strtotime('-29 days')), $today];
            case 'last_month':   return [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))];
            case 'this_quarter': $q = floor((date('n') - 1) / 3); return [date('Y-' . str_pad($q * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01'), $today];
            case 'this_year':    return [date('Y-01-01'), $today];
            case 'last_year':    return [date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year'))];
            case 'this_month':
            default:             return [date('Y-m-01'), $today];
        }
    }
}

if (!function_exists('aiInsightRegistry')) {
    /** name => ['description','params'=>[name=>hint], 'run'=>fn(array $args, PDO):array] */
    function aiInsightRegistry(): array
    {
        return [
            'revenue' => [
                'description' => 'Total sales for a period: invoices plus shop/POS sales (the same figure as the Sales report).',
                'params' => ['period' => 'today|yesterday|last_7_days|this_month|last_month|this_year|last_30_days|this_quarter', 'from' => 'YYYY-MM-DD (optional)', 'to' => 'YYYY-MM-DD (optional)'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a); [$u, $p] = _aiSalesRows($f, $t);
                    $s = $pdo->prepare("SELECT src, COALESCE(SUM(amt),0) total, COUNT(*) n FROM ($u) x GROUP BY src");
                    $s->execute($p);
                    $by = ['invoice' => ['total' => 0.0, 'n' => 0], 'pos' => ['total' => 0.0, 'n' => 0]];
                    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['src']] = ['total' => (float)$r['total'], 'n' => (int)$r['n']];
                    return ['period' => "$f to $t", 'revenue' => round($by['invoice']['total'] + $by['pos']['total'], 2),
                            'invoice_sales' => round($by['invoice']['total'], 2), 'invoice_count' => $by['invoice']['n'],
                            'pos_sales' => round($by['pos']['total'], 2), 'pos_sale_count' => $by['pos']['n']];
                },
            ],
            'expenses_total' => [
                'description' => 'Total business expenses for a period, from the books (the same figures as the Income Statement).',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a);
                    $pl = glProfitLoss($pdo, $f, $t, null, _aiJeScope());
                    return ['period' => "$f to $t", 'expenses' => $pl['total_expense'], 'cost_of_goods_sold' => $pl['total_cogs'],
                            'finance_costs' => $pl['total_finance_cost'],
                            'by_account' => array_map(fn($l) => ['account' => $l['account_name'], 'amount' => $l['amount']], array_slice($pl['expense'], 0, 15))];
                },
            ],
            'profit' => [
                'description' => 'Profit for a period from the books: sales, cost of goods sold, gross profit, expenses and net profit (the same figures as the Income Statement).',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a);
                    $pl = glProfitLoss($pdo, $f, $t, null, _aiJeScope());
                    return ['period' => "$f to $t", 'revenue' => $pl['total_revenue'], 'other_income' => $pl['total_other_income'],
                            'cost_of_goods_sold' => $pl['total_cogs'], 'gross_profit' => $pl['gross_profit'],
                            'expenses' => $pl['total_expense'], 'finance_costs' => $pl['total_finance_cost'], 'profit' => $pl['net_profit']];
                },
            ],
            'outstanding_receivables' => [
                'description' => 'Total amount customers still owe: unpaid invoices plus unpaid shop credit sales.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $rows = $pdo->query("SELECT src, COALESCE(SUM(owed),0) total, COUNT(*) n FROM (" . _aiBalances($pdo) . ") b GROUP BY src")->fetchAll(PDO::FETCH_ASSOC);
                    $by = ['invoice' => ['total' => 0.0, 'n' => 0], 'pos' => ['total' => 0.0, 'n' => 0]];
                    foreach ($rows as $r) $by[$r['src']] = ['total' => (float)$r['total'], 'n' => (int)$r['n']];
                    return ['outstanding_receivables' => round($by['invoice']['total'] + $by['pos']['total'], 2),
                            'unpaid_invoices_total' => round($by['invoice']['total'], 2), 'unpaid_invoices' => $by['invoice']['n'],
                            'unpaid_credit_sales_total' => round($by['pos']['total'], 2), 'unpaid_credit_sales' => $by['pos']['n']];
                },
            ],
            'top_debtors' => [
                'description' => 'Customers who owe the most (unpaid invoices and shop credit sales).',
                'params' => ['limit' => 'how many (default 5)'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(20, (int)($a['limit'] ?? 5)));
                    $s = $pdo->query("SELECT c.customer_name, COALESCE(SUM(b.owed),0) owed
                                        FROM (" . _aiBalances($pdo) . ") b JOIN customers c ON c.customer_id = b.cid
                                    GROUP BY c.customer_id, c.customer_name ORDER BY owed DESC LIMIT $n");
                    return ['top_debtors' => array_map(fn($x) => ['customer' => $x['customer_name'], 'owed' => round((float)$x['owed'], 2)], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'top_customers' => [
                'description' => 'Best customers by sales total for a period (invoices plus shop sales; walk-in sales grouped as "Walk-in").',
                'params' => ['limit' => 'default 5', 'period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(20, (int)($a['limit'] ?? 5))); [$f, $t] = _aiPeriod($a); [$u, $p] = _aiSalesRows($f, $t);
                    $s = $pdo->prepare("SELECT COALESCE(c.customer_name, 'Walk-in') customer_name, COALESCE(SUM(x.amt),0) sales
                                          FROM ($u) x LEFT JOIN customers c ON c.customer_id = x.cid
                                      GROUP BY x.cid, c.customer_name ORDER BY sales DESC LIMIT $n");
                    $s->execute($p);
                    return ['period' => "$f to $t", 'top_customers' => array_map(fn($x) => ['customer' => $x['customer_name'], 'sales' => round((float)$x['sales'], 2)], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'cash_position' => [
                'description' => 'Current balance of each cash and bank account and the total, from the books (the same as the Balance Sheet).',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $ids = array_flip(glCashAccountIds($pdo));
                    $bs = glBalanceSheet($pdo, date('Y-m-d'), null, false, _aiJeScope());
                    $rows = array_values(array_filter($bs['assets'], fn($l) => $l['account_id'] !== null && isset($ids[(int)$l['account_id']]) && abs($l['amount']) >= 0.005));
                    usort($rows, fn($x, $y) => $y['amount'] <=> $x['amount']);
                    $total = 0.0; foreach ($rows as $r) $total += $r['amount'];
                    return ['total_cash' => round($total, 2), 'accounts' => array_map(fn($r) => ['account' => $r['account_name'], 'balance' => $r['amount']], $rows)];
                },
            ],
            'ar_aging_summary' => [
                'description' => 'Unpaid invoices grouped by how overdue they are (current, 1-30, 31-60, 61-90, 90+ days). Invoices only.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $sc = _aiScope('i') . _aiWhScope('i');
                    $s = $pdo->query("SELECT
                          SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 0 THEN balance_due ELSE 0 END) cur,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN balance_due ELSE 0 END) d30,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN balance_due ELSE 0 END) d60,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN balance_due ELSE 0 END) d90,
                          SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN balance_due ELSE 0 END) d90p
                        FROM invoices i WHERE i.status<>'deleted' AND i.balance_due > 0 $sc")->fetch(PDO::FETCH_ASSOC);
                    return ['current' => (float)$s['cur'], '1_30' => (float)$s['d30'], '31_60' => (float)$s['d60'], '61_90' => (float)$s['d90'], 'over_90' => (float)$s['d90p']];
                },
            ],
            'low_stock' => [
                'description' => 'Products at or below their minimum stock level (reorder needed), counting only the shops/warehouses this person may see.',
                'params' => ['limit' => 'default 10'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(50, (int)($a['limit'] ?? 10)));
                    // Same rule and scope as the Products page Low Stock card.
                    $base = "FROM products p
                        LEFT JOIN (SELECT ps2.product_id, SUM(ps2.stock_quantity - ps2.reserved_quantity) available
                                     FROM product_stocks ps2 WHERE 1=1" . _aiWhScope('ps2', true) . "
                                 GROUP BY ps2.product_id) st ON st.product_id = p.product_id
                        WHERE p.is_service = 0 AND p.status = 'active' AND p.min_stock_level > 0
                          AND COALESCE(st.available, 0) <= p.min_stock_level" . _aiScope('p') . _aiWhScope('p');
                    $cnt = (int)$pdo->query("SELECT COUNT(*) $base")->fetchColumn();
                    $s = $pdo->query("SELECT p.product_name, COALESCE(st.available, 0) stock, p.min_stock_level $base ORDER BY (COALESCE(st.available, 0) - p.min_stock_level) ASC LIMIT $n");
                    return ['below_minimum_count' => $cnt, 'items' => array_map(fn($r) => ['product' => $r['product_name'], 'stock' => (float)$r['stock'], 'minimum' => (float)$r['min_stock_level']], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'invoice_status_counts' => [
                'description' => 'How many invoices are in each status for a period.',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a); $sc = _aiScope('i') . _aiWhScope('i');
                    $s = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(grand_total),0) total FROM invoices i WHERE i.invoice_date BETWEEN ? AND ? $sc GROUP BY status");
                    $s->execute([$f, $t]);
                    return ['period' => "$f to $t", 'by_status' => $s->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],
            'sales_trend' => [
                'description' => 'Monthly sales totals (invoices plus shop sales) for the last N months (default 6).',
                'params' => ['months' => 'default 6'],
                'run' => function (array $a, PDO $pdo) {
                    $m = max(2, min(24, (int)($a['months'] ?? 6)));
                    [$u, $p] = _aiSalesRows(date('Y-m-01', strtotime("-$m months", strtotime(date('Y-m-01')))), date('Y-m-d'));
                    $s = $pdo->prepare("SELECT DATE_FORMAT(x.d, '%Y-%m') ym, ROUND(COALESCE(SUM(x.amt),0), 2) sales FROM ($u) x GROUP BY ym ORDER BY ym");
                    $s->execute($p);
                    return ['monthly_sales' => $s->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],

            // ── Operational modules (projects, procurement, sales, HR) ──────────
            'projects_summary' => [
                'description' => 'Projects: how many active, their total contract value, and the list.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $sc = _aiScope('p');
                    $rows = $pdo->query("SELECT project_name, client_name, contract_sum, status FROM projects p WHERE 1=1 $sc ORDER BY contract_sum DESC")->fetchAll(PDO::FETCH_ASSOC);
                    $total = 0; foreach ($rows as $r) $total += (float)$r['contract_sum'];
                    return ['project_count' => count($rows), 'total_contract_value' => round($total, 2),
                            'projects' => array_map(fn($r) => ['name' => $r['project_name'], 'client' => $r['client_name'], 'contract_value' => (float)$r['contract_sum'], 'status' => $r['status']], $rows)];
                },
            ],
            'purchase_orders_summary' => [
                'description' => 'Purchase orders: counts and value by status, and how many await review/approval.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    $where = "status <> 'deleted'"; $params = [];
                    if (!empty($a['period']) || (!empty($a['from']) && !empty($a['to']))) { [$f, $t] = _aiPeriod($a); $where .= " AND order_date BETWEEN ? AND ?"; $params = [$f, $t]; }
                    $s = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(grand_total),0) value FROM purchase_orders WHERE $where GROUP BY status");
                    $s->execute($params);
                    $by = $s->fetchAll(PDO::FETCH_ASSOC);
                    $pending = 0; foreach ($by as $r) if (in_array($r['status'], ['pending', 'reviewed'], true)) $pending += (int)$r['n'];
                    return ['by_status' => $by, 'awaiting_approval' => $pending];
                },
            ],
            'sales_orders_summary' => [
                'description' => 'Sales orders: counts and total value by status for a period.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    $where = "status <> 'deleted'"; $params = []; $sc = _aiScope('so');
                    if (!empty($a['period']) || (!empty($a['from']) && !empty($a['to']))) { [$f, $t] = _aiPeriod($a); $where .= " AND so.order_date BETWEEN ? AND ?"; $params = [$f, $t]; }
                    $s = $pdo->prepare("SELECT so.status, COUNT(*) n, COALESCE(SUM(so.total_amount),0) value FROM sales_orders so WHERE $where $sc GROUP BY so.status");
                    $s->execute($params);
                    return ['by_status' => $s->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],
            'quotations_summary' => [
                'description' => 'Quotations: counts and value by status.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    return ['by_status' => $pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(total_amount),0) value FROM quotations WHERE status<>'deleted' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],
            'suppliers_count' => [
                'description' => 'How many suppliers/vendors are registered (active).',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    return ['active_suppliers' => (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE status='active'")->fetchColumn()];
                },
            ],
            'employees_summary' => [
                'description' => 'Staff headcount by employment status (active, probation, contract, terminated).',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $by = $pdo->query("SELECT employment_status, COUNT(*) n FROM employees GROUP BY employment_status")->fetchAll(PDO::FETCH_ASSOC);
                    $active = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status='active'")->fetchColumn();
                    return ['active_employees' => $active, 'by_status' => $by];
                },
            ],
            'payroll_summary' => [
                'description' => 'Payroll totals (gross and net) for a period.',
                'params' => ['period' => 'optional', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a);
                    $s = $pdo->prepare("SELECT COUNT(*) runs, COALESCE(SUM(gross_salary),0) gross, COALESCE(SUM(net_salary),0) net FROM payroll WHERE payroll_date BETWEEN ? AND ?");
                    $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC);
                    return ['period' => "$f to $t", 'payslips' => (int)$r['runs'], 'gross_total' => (float)$r['gross'], 'net_total' => (float)$r['net']];
                },
            ],
            'pending_leaves' => [
                'description' => 'Leave requests awaiting approval.',
                'params' => ['limit' => 'default 10'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(50, (int)($a['limit'] ?? 10)));
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE status='pending'")->fetchColumn();
                    $s = $pdo->query("SELECT e.first_name, e.last_name, l.leave_type, l.start_date, l.end_date, l.total_days
                                        FROM leaves l JOIN employees e ON e.employee_id=l.employee_id
                                       WHERE l.status='pending' ORDER BY l.start_date LIMIT $n");
                    return ['pending_count' => $cnt, 'requests' => array_map(fn($r) => ['employee' => trim($r['first_name'] . ' ' . $r['last_name']), 'type' => $r['leave_type'], 'from' => $r['start_date'], 'to' => $r['end_date'], 'days' => (float)$r['total_days']], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'pending_approvals' => [
                'description' => 'Things waiting on someone: purchase orders and sales orders not yet approved.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $po = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('pending','reviewed')")->fetchColumn();
                    $so = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status IN ('pending','reviewed')")->fetchColumn();
                    $lv = (int)$pdo->query("SELECT COUNT(*) FROM leaves WHERE status='pending'")->fetchColumn();
                    return ['purchase_orders_awaiting' => $po, 'sales_orders_awaiting' => $so, 'leave_requests_awaiting' => $lv];
                },
            ],

            // ── How-to / usage help (grounded in the system user guide) ─────────
            'search_help' => [
                'description' => 'Look up HOW to use a feature of the system (e.g. how to create an invoice, add a supplier, run payroll, where a setting is). Use this for "how do I…", "where is…", "what does X do" questions.',
                'params' => ['query' => 'the user\'s how-to question or keywords'],
                'run' => function (array $a, PDO $pdo) {
                    $q = (string)($a['query'] ?? '');
                    // Published help articles first: only those this user can reach, in this company's
                    // own words (Shop vs Warehouse, Sell vs Sales › POS) — support_center_plan.md D8d.
                    if (is_file(__DIR__ . '/support_articles.php')) {
                        require_once __DIR__ . '/support_articles.php';
                        $ctx = helpRuntimeContext();
                        $lang = function_exists('currentLanguage') && currentLanguage() === 'sw' ? 'sw' : 'en';
                        $hits = supportTenantSearch($q, $ctx, $lang);
                        if ($hits) {
                            return ['help_articles' => array_map(fn($h) => ['title' => $h['title'], 'steps' => mb_substr($h['_body'], 0, 1500)], array_slice($hits, 0, 3)),
                                    'note' => 'Answer with these steps and menu paths exactly; they match what this user sees.'];
                        }
                        if (supportTenantList($ctx, $lang)) return ['note' => 'No help article matches this question.'];
                        // No published articles at all yet: the general guide describes full Smart BMS
                        // menus, so never use it for a Simple Mode (Smart POS) shop.
                        if (!empty($ctx['simple'])) return ['note' => 'No help article matches this question.'];
                    }
                    if (!function_exists('aiSearchHelp')) return ['error' => 'Help guide not available.'];
                    $hits = aiSearchHelp($q, 4);
                    if (!$hits) return ['note' => 'No matching help section found in the user guide.'];
                    return ['help_sections' => array_map(fn($h) => ['topic' => $h['title'], 'content' => $h['text']], $hits)];
                },
            ],
        ];
    }
}

if (!function_exists('aiInsightPages')) {
    /**
     * The pages a user must be able to open (any one of them) before the AI may use a data
     * function for them — so the assistant never tells someone a figure their own menus hide
     * (support_center_plan.md D8d). null = available to everyone signed in.
     */
    function aiInsightPages(): array
    {
        return [
            'revenue' => ['invoices', 'sales_report', 'income_statement'],
            'expenses_total' => ['expenses', 'expense_report'],
            'profit' => ['income_statement', 'pos_profit_report'],
            'outstanding_receivables' => ['invoices', 'financial_reports'],
            'top_debtors' => ['invoices', 'financial_reports'],
            'top_customers' => ['customers', 'sales_report'],
            'cash_position' => ['bank_accounts', 'cash_register'],
            'ar_aging_summary' => ['financial_reports'],
            'low_stock' => ['products', 'inventory_report'],
            'invoice_status_counts' => ['invoices'],
            'sales_trend' => ['sales_report'],
            'projects_summary' => ['projects'],
            'purchase_orders_summary' => ['purchase_orders'],
            'sales_orders_summary' => ['sales_orders'],
            'quotations_summary' => ['quotations'],
            'suppliers_count' => ['suppliers'],
            'employees_summary' => ['employees'],
            'payroll_summary' => ['payroll'],
            'pending_leaves' => ['leaves'],
            'pending_approvals' => ['__admin__'],
            'search_help' => null,
        ];
    }
}

if (!function_exists('aiInsightAllowed')) {
    /** May the signed-in user use this data function? Unknown names are never allowed. */
    function aiInsightAllowed(string $name): bool
    {
        $map = aiInsightPages();
        if (!array_key_exists($name, $map)) return false;
        $pages = $map[$name];
        if ($pages === null) return true;
        if (PHP_SAPI === 'cli' && empty($_SESSION['user_id'])) return true;   // CLI tools / tests without a user
        foreach ($pages as $p) {
            if ($p === '__admin__') { if (function_exists('isAdmin') && isAdmin()) return true; continue; }
            if (function_exists('canView') && canView($p)) return true;
        }
        return false;
    }
}

if (!function_exists('aiInsightCatalog')) {
    /** Lightweight catalog (no closures) to put in the model prompt — only what this user may use. */
    function aiInsightCatalog(): array
    {
        $out = [];
        foreach (aiInsightRegistry() as $name => $def) {
            if (!aiInsightAllowed($name)) continue;
            $out[] = ['name' => $name, 'description' => $def['description'], 'params' => $def['params']];
        }
        return $out;
    }
}

if (!function_exists('aiRunInsight')) {
    function aiRunInsight(string $name, array $args = []): array
    {
        global $pdo;
        $reg = aiInsightRegistry();
        if (!isset($reg[$name])) return ['ok' => false, 'error' => "Unknown insight: $name"];
        if (!aiInsightAllowed($name)) return ['ok' => false, 'error' => 'That information is not available to your role.'];
        try {
            $data = $reg[$name]['run']($args, $pdo);
            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            error_log("aiRunInsight[$name]: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not compute that insight.'];
        }
    }
}
