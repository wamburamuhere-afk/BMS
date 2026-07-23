<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/financial_reports.php';   // glCashAccountIds()

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Role gate — must match the dashboard Performance Overview card gate
if (!hasReportsAccess() && !canView('invoices') && !canView('sales_report')) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

try {
    $period = $_GET['period'] ?? 'monthly';
    $data = [];

    // Date range logic
    $endDate = date('Y-m-d');
    if ($period === 'monthly') {
        $startDate = date('Y-m-d', strtotime('-11 months'));
        $dateFormat = '%Y-%m';
    } elseif ($period === 'weekly') {
        $startDate = date('Y-m-d', strtotime('-12 weeks'));
        $dateFormat = '%x-%v';
    } elseif ($period === 'quarterly') {
        $startDate = date('Y-m-d', strtotime('-2 years'));
        $dateFormat = 'QUARTER';
    } else { // yearly
        $startDate = date('Y-m-d', strtotime('-10 years'));
        $dateFormat = '%Y';
    }

    // ------------------------------------------------------------------
    // ALL figures come from the ONE canonical ledger (posted journal only)
    // — never raw invoices/pos_sales/expenses. See .claude/reporting-source.md.
    //   Sales / Expenses / Net Profit  = accrual view  (Income Statement basis)
    //   Cash In / Cash Out / Net Cash  = cash view      (Cash Flow basis)
    // Both read journal_entries ⨝ journal_entry_items ⨝ accounts so the card
    // reconciles with the Income Statement and Cash Flow reports by construction.
    // ------------------------------------------------------------------

    // Project scope — applied on the journal header for non-admins (assigned
    // projects OR untagged company-wide rows). Same fragment feeds every query.
    $scope = scopeFilterSqlNullable('project', 'je');

    // Period expression on the journal header date (source-document date).
    $periodSql = ($period === 'quarterly')
        ? "CONCAT(YEAR(je.entry_date), '-Q', QUARTER(je.entry_date))"
        : "DATE_FORMAT(je.entry_date, '$dateFormat')";

    // 1. Accrual: Revenue and Expenses per period, classified by account_types.
    //    Revenue  = credit-normal income  (credit − debit) for revenue/other_income
    //    Expenses = debit-normal cost      (debit − credit) for cogs/expense/finance
    $accrualQuery = "
        SELECT
            $periodSql AS period,
            SUM(CASE WHEN at.category IN ('revenue','other_income')
                     THEN (CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END)
                     ELSE 0 END) AS revenue,
            SUM(CASE WHEN at.category IN ('cogs','expense','finance_cost')
                     THEN (CASE WHEN jei.type='debit'  THEN jei.amount ELSE -jei.amount END)
                     ELSE 0 END) AS expense
        FROM journal_entry_items jei
        JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
        JOIN accounts        a  ON a.account_id = jei.account_id
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        WHERE je.entry_date BETWEEN ? AND ?
          {$scope}
        GROUP BY period
    ";
    $stmt = $pdo->prepare($accrualQuery);
    $stmt->execute([$startDate, $endDate]);
    $accrual = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['period'])) {
            $accrual[$row['period']] = [
                'revenue' => (float)$row['revenue'],
                'expense' => (float)$row['expense'],
            ];
        }
    }

    // 2. Cash: money that actually moved through cash/bank accounts per period.
    //    Cash In  = debits to cash accounts,  Cash Out = credits from them.
    $cashByPeriod = [];
    $cashIds = glCashAccountIds($pdo);
    if (!empty($cashIds)) {
        $in = implode(',', array_map('intval', $cashIds));
        $cashQuery = "
            SELECT
                $periodSql AS period,
                SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END) AS cash_in,
                SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END) AS cash_out
            FROM journal_entry_items jei
            JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
            WHERE jei.account_id IN ($in)
              AND je.entry_date BETWEEN ? AND ?
              {$scope}
            GROUP BY period
        ";
        $stmt = $pdo->prepare($cashQuery);
        $stmt->execute([$startDate, $endDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['period'])) {
                $cashByPeriod[$row['period']] = [
                    'cash_in'  => (float)$row['cash_in'],
                    'cash_out' => (float)$row['cash_out'],
                ];
            }
        }
    }

    // 3. Merge period keys from both views and emit a continuous series.
    $allKeys = array_unique(array_merge(array_keys($accrual), array_keys($cashByPeriod)));
    sort($allKeys);

    $emptyIndex = array_search('', $allKeys);
    if ($emptyIndex !== false) {
        unset($allKeys[$emptyIndex]);
    }

    $prevRevenue = 0;
    foreach ($allKeys as $key) {
        $rev     = (float)($accrual[$key]['revenue'] ?? 0);
        $exp     = (float)($accrual[$key]['expense'] ?? 0);
        $cashIn  = (float)($cashByPeriod[$key]['cash_in']  ?? 0);
        $cashOut = (float)($cashByPeriod[$key]['cash_out'] ?? 0);

        $label = $key;
        if ($period === 'monthly') {
            $label = date('M Y', strtotime($key . '-01'));
        } elseif ($period === 'weekly') {
            $parts = explode('-', $key);
            $label = (count($parts) == 2) ? "Wk {$parts[1]}, {$parts[0]}" : "Wk " . $key;
        }

        $growth = $prevRevenue > 0 ? (($rev - $prevRevenue) / $prevRevenue) * 100 : 0;

        $data[] = [
            'period'    => $label,
            'revenue'   => $rev,                    // accrual sales (Income Statement)
            'expense'   => $exp,                    // accrual expenses
            'net_profit'=> round($rev - $exp, 2),   // = Income Statement net profit
            'collected' => $cashIn,                 // cash actually received (Cash In)
            'cash_out'  => $cashOut,                // cash actually paid out
            'net_cash'  => round($cashIn - $cashOut, 2), // real change in cash position
            'growth'    => round($growth, 1),
        ];
        $prevRevenue = $rev;
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log("Performance API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
