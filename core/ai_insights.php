<?php
/**
 * core/ai_insights.php
 * --------------------
 * The ONLY way the AI Assistant can read business data. A fixed registry of
 * read-only, parameterised aggregate functions. The model may choose a function
 * + args; BMS runs it and returns a small result for the model to phrase. The
 * model never sees raw rows, never writes SQL, and can never modify anything.
 *
 * Scope-aware: invoice-based functions apply the same project-scope filter as
 * the reports, so a scoped user's answers cover only their data.
 *
 * Public API:
 *   aiInsightCatalog(): array         — catalog (name, description, params) for the prompt
 *   aiRunInsight(string, array): array — ['ok'=>bool,'data'=>mixed,'error'=>?string]
 */

if (file_exists(__DIR__ . '/project_scope.php')) require_once __DIR__ . '/project_scope.php';
if (file_exists(__DIR__ . '/ai_help.php')) require_once __DIR__ . '/ai_help.php';

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
                'description' => 'Total sales/revenue (sum of invoice totals) for a period.',
                'params' => ['period' => 'this_month|last_month|this_year|last_30_days|this_quarter', 'from' => 'YYYY-MM-DD (optional)', 'to' => 'YYYY-MM-DD (optional)'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a); $sc = _aiScope('i');
                    $s = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) total, COUNT(*) n FROM invoices i WHERE i.status <> 'deleted' AND i.invoice_date BETWEEN ? AND ? $sc");
                    $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC);
                    return ['period' => "$f to $t", 'revenue' => (float)$r['total'], 'invoice_count' => (int)$r['n']];
                },
            ],
            'expenses_total' => [
                'description' => 'Total business expenses for a period.',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a);
                    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) n FROM expenses WHERE (status IS NULL OR status <> 'deleted') AND expense_date BETWEEN ? AND ?");
                    $s->execute([$f, $t]); $r = $s->fetch(PDO::FETCH_ASSOC);
                    return ['period' => "$f to $t", 'expenses' => (float)$r['total'], 'expense_count' => (int)$r['n']];
                },
            ],
            'profit' => [
                'description' => 'Rough profit = revenue minus expenses for a period.',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a); $sc = _aiScope('i');
                    $rev = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM invoices i WHERE i.status<>'deleted' AND i.invoice_date BETWEEN " . $pdo->quote($f) . " AND " . $pdo->quote($t) . " $sc")->fetchColumn();
                    $exp = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE (status IS NULL OR status<>'deleted') AND expense_date BETWEEN " . $pdo->quote($f) . " AND " . $pdo->quote($t))->fetchColumn();
                    return ['period' => "$f to $t", 'revenue' => $rev, 'expenses' => $exp, 'profit' => round($rev - $exp, 2)];
                },
            ],
            'outstanding_receivables' => [
                'description' => 'Total amount customers still owe (unpaid invoice balances).',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $sc = _aiScope('i');
                    $r = $pdo->query("SELECT COALESCE(SUM(balance_due),0) total, COUNT(*) n FROM invoices i WHERE i.status<>'deleted' AND i.balance_due > 0 $sc")->fetch(PDO::FETCH_ASSOC);
                    return ['outstanding_receivables' => (float)$r['total'], 'unpaid_invoices' => (int)$r['n']];
                },
            ],
            'top_debtors' => [
                'description' => 'Customers who owe the most (by unpaid balance).',
                'params' => ['limit' => 'how many (default 5)'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(20, (int)($a['limit'] ?? 5))); $sc = _aiScope('i');
                    $s = $pdo->prepare("SELECT c.customer_name, COALESCE(SUM(i.balance_due),0) owed
                                          FROM invoices i JOIN customers c ON c.customer_id = i.customer_id
                                         WHERE i.status<>'deleted' AND i.balance_due > 0 $sc
                                      GROUP BY c.customer_id, c.customer_name ORDER BY owed DESC LIMIT $n");
                    $s->execute();
                    return ['top_debtors' => array_map(fn($x) => ['customer' => $x['customer_name'], 'owed' => (float)$x['owed']], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'top_customers' => [
                'description' => 'Best customers by sales total for a period.',
                'params' => ['limit' => 'default 5', 'period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(20, (int)($a['limit'] ?? 5))); [$f, $t] = _aiPeriod($a); $sc = _aiScope('i');
                    $s = $pdo->prepare("SELECT c.customer_name, COALESCE(SUM(i.grand_total),0) sales
                                          FROM invoices i JOIN customers c ON c.customer_id=i.customer_id
                                         WHERE i.status<>'deleted' AND i.invoice_date BETWEEN ? AND ? $sc
                                      GROUP BY c.customer_id, c.customer_name ORDER BY sales DESC LIMIT $n");
                    $s->execute([$f, $t]);
                    return ['period' => "$f to $t", 'top_customers' => array_map(fn($x) => ['customer' => $x['customer_name'], 'sales' => (float)$x['sales']], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'cash_position' => [
                'description' => 'Current total balance across cash and bank accounts.',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $s = $pdo->query("SELECT a.account_name, a.current_balance FROM accounts a
                                       WHERE a.status='active' AND a.account_type='asset' AND a.cash_flow_category='cash'
                                         AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_account_id=a.account_id)
                                    ORDER BY a.current_balance DESC");
                    $rows = $s->fetchAll(PDO::FETCH_ASSOC); $total = 0;
                    foreach ($rows as $r) $total += (float)$r['current_balance'];
                    return ['total_cash' => round($total, 2), 'accounts' => array_map(fn($r) => ['account' => $r['account_name'], 'balance' => (float)$r['current_balance']], $rows)];
                },
            ],
            'ar_aging_summary' => [
                'description' => 'Receivables grouped by how overdue they are (current, 1-30, 31-60, 61-90, 90+ days).',
                'params' => [],
                'run' => function (array $a, PDO $pdo) {
                    $sc = _aiScope('i');
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
                'description' => 'Products at or below their minimum stock level (reorder needed).',
                'params' => ['limit' => 'default 10'],
                'run' => function (array $a, PDO $pdo) {
                    $n = max(1, min(50, (int)($a['limit'] ?? 10)));
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE current_stock <= min_stock_level")->fetchColumn();
                    $s = $pdo->query("SELECT product_name, current_stock, min_stock_level FROM products WHERE current_stock <= min_stock_level ORDER BY (current_stock - min_stock_level) ASC LIMIT $n");
                    return ['below_minimum_count' => $cnt, 'items' => array_map(fn($r) => ['product' => $r['product_name'], 'stock' => (float)$r['current_stock'], 'minimum' => (float)$r['min_stock_level']], $s->fetchAll(PDO::FETCH_ASSOC))];
                },
            ],
            'invoice_status_counts' => [
                'description' => 'How many invoices are in each status for a period.',
                'params' => ['period' => 'as above', 'from' => 'optional', 'to' => 'optional'],
                'run' => function (array $a, PDO $pdo) {
                    [$f, $t] = _aiPeriod($a); $sc = _aiScope('i');
                    $s = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(grand_total),0) total FROM invoices i WHERE i.invoice_date BETWEEN ? AND ? $sc GROUP BY status");
                    $s->execute([$f, $t]);
                    return ['period' => "$f to $t", 'by_status' => $s->fetchAll(PDO::FETCH_ASSOC)];
                },
            ],
            'sales_trend' => [
                'description' => 'Monthly sales totals for the last N months (default 6).',
                'params' => ['months' => 'default 6'],
                'run' => function (array $a, PDO $pdo) {
                    $m = max(2, min(24, (int)($a['months'] ?? 6))); $sc = _aiScope('i');
                    $s = $pdo->prepare("SELECT DATE_FORMAT(invoice_date, '%Y-%m') ym, COALESCE(SUM(grand_total),0) sales
                                          FROM invoices i WHERE i.status<>'deleted' AND i.invoice_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL $m MONTH) $sc
                                      GROUP BY ym ORDER BY ym");
                    $s->execute();
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
                    if (!function_exists('aiSearchHelp')) return ['error' => 'Help guide not available.'];
                    $hits = aiSearchHelp((string)($a['query'] ?? ''), 4);
                    if (!$hits) return ['note' => 'No matching help section found in the user guide.'];
                    return ['help_sections' => array_map(fn($h) => ['topic' => $h['title'], 'content' => $h['text']], $hits)];
                },
            ],
        ];
    }
}

if (!function_exists('aiInsightCatalog')) {
    /** Lightweight catalog (no closures) to put in the model prompt. */
    function aiInsightCatalog(): array
    {
        $out = [];
        foreach (aiInsightRegistry() as $name => $def) {
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
        try {
            $data = $reg[$name]['run']($args, $pdo);
            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            error_log("aiRunInsight[$name]: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not compute that insight.'];
        }
    }
}
