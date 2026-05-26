<?php
require_once __DIR__ . '/../roots.php';

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
        $interval = 'P1M';
    } elseif ($period === 'weekly') {
        $startDate = date('Y-m-d', strtotime('-12 weeks'));
        $dateFormat = '%x-%v';
        $interval = 'P1W';
    } elseif ($period === 'quarterly') {
        $startDate = date('Y-m-d', strtotime('-2 years'));
        $dateFormat = 'QUARTER';
        $interval = 'P3M';
    } else { // yearly
        $startDate = date('Y-m-d', strtotime('-10 years'));
        $dateFormat = '%Y';
        $interval = 'P1Y';
    }

    // Project scope filters
    // invoices: NULL project_id = global invoice, always included
    // pos_sales: no project_id column (shared terminal) — no scope
    // expenses: NULL project_id = global expense, always included
    $invScope = scopeFilterSqlNullable('project', 'invoices');
    $expScope = scopeFilterSqlNullable('project', 'e');

    // Period expression per table
    $periodSql = ($period === 'quarterly')
        ? "CONCAT(YEAR(invoice_date), '-Q', QUARTER(invoice_date))"
        : "DATE_FORMAT(invoice_date, '$dateFormat')";

    $periodSqlPos = ($period === 'quarterly')
        ? "CONCAT(YEAR(sale_date), '-Q', QUARTER(sale_date))"
        : "DATE_FORMAT(sale_date, '$dateFormat')";

    // 1. Revenue = invoices (scoped) + POS sales (unscoped — shared terminal)
    $revenueQuery = "
        SELECT period, SUM(revenue) as revenue, SUM(transactions) as transactions FROM (
            SELECT
                $periodSql as period,
                SUM(grand_total) as revenue,
                COUNT(*) as transactions
            FROM invoices
            WHERE status NOT IN ('draft', 'cancelled')
              AND invoice_date BETWEEN ? AND ?
              {$invScope}
            GROUP BY period
            UNION ALL
            SELECT
                $periodSqlPos as period,
                SUM(grand_total) as revenue,
                COUNT(*) as transactions
            FROM pos_sales
            WHERE sale_status = 'completed'
              AND sale_date BETWEEN ? AND ?
            GROUP BY period
        ) t
        GROUP BY period
    ";

    $stmt = $pdo->prepare($revenueQuery);
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $revenues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $revenueData = [];
    foreach ($revenues as $row) {
        if (!empty($row['period'])) {
            $revenueData[$row['period']] = ['revenue' => $row['revenue'], 'transactions' => $row['transactions']];
        }
    }

    // 2. Expenses (scoped by project; NULL project_id = global expense)
    $expPeriodSql = ($period === 'quarterly')
        ? "CONCAT(YEAR(expense_date), '-Q', QUARTER(expense_date))"
        : "DATE_FORMAT(expense_date, '$dateFormat')";

    $expenseQuery = "
        SELECT
            $expPeriodSql as period,
            SUM(amount) as expense
        FROM expenses e
        WHERE status IN ('approved', 'paid')
          AND expense_date BETWEEN ? AND ?
          {$expScope}
        GROUP BY period
    ";

    $stmt = $pdo->prepare($expenseQuery);
    $stmt->execute([$startDate, $endDate]);
    $expenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 3. Merge and generate continuity
    $allKeys = array_unique(array_merge(array_keys($revenueData), array_keys($expenses)));
    sort($allKeys);

    $emptyIndex = array_search('', $allKeys);
    if ($emptyIndex !== false) {
        unset($allKeys[$emptyIndex]);
    }

    $prevRevenue = 0;
    foreach ($allKeys as $key) {
        $rev   = floatval($revenueData[$key]['revenue'] ?? 0);
        $trans = intval($revenueData[$key]['transactions'] ?? 0);
        $exp   = floatval($expenses[$key] ?? 0);

        $label = $key;
        if ($period === 'monthly') {
            $label = date('M Y', strtotime($key . '-01'));
        } elseif ($period === 'weekly') {
            $parts = explode('-', $key);
            $label = (count($parts) == 2) ? "Wk {$parts[1]}, {$parts[0]}" : "Wk " . $key;
        }

        $growth = $prevRevenue > 0 ? (($rev - $prevRevenue) / $prevRevenue) * 100 : 0;

        $data[] = [
            'period'       => $label,
            'revenue'      => $rev,
            'transactions' => $trans,
            'expense'      => $exp,
            'growth'       => round($growth, 1)
        ];
        $prevRevenue = $rev;
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log("Performance API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
