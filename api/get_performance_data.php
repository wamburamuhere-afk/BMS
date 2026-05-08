<?php
require_once __DIR__ . '/../roots.php';
// roots.php includes config.php and helpers.php already

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $period = $_GET['period'] ?? 'monthly';
    $data = [];

    // Date range logic
    $endDate = date('Y-m-d');
    if ($period === 'monthly') {
        $startDate = date('Y-m-d', strtotime('-11 months')); // Show last 12 months for better context
        $dateFormat = '%Y-%m';
        $interval = 'P1M';
    } elseif ($period === 'weekly') {
        $startDate = date('Y-m-d', strtotime('-12 weeks')); // Show last 12 weeks
        $dateFormat = '%x-%v'; // Year-Week (ISO)
        $interval = 'P1W';
    } elseif ($period === 'quarterly') {
        $startDate = date('Y-m-d', strtotime('-2 years'));
        $dateFormat = 'QUARTER'; // Special handling in SQL
        $interval = 'P3M';
    } else { // yearly
        $startDate = date('Y-m-d', strtotime('-10 years'));
        $dateFormat = '%Y';
        $interval = 'P1Y';
    }

    // Adjust revenue query based on format
    $periodSql = ($period === 'quarterly') 
        ? "CONCAT(YEAR(invoice_date), '-Q', QUARTER(invoice_date))" 
        : "DATE_FORMAT(invoice_date, '$dateFormat')";
    
    $periodSqlPos = ($period === 'quarterly') 
        ? "CONCAT(YEAR(sale_date), '-Q', QUARTER(sale_date))" 
        : "DATE_FORMAT(sale_date, '$dateFormat')";

    // 1. Fetch Revenue (Invoices + POS) and Transactions
    $revenueQuery = "
        SELECT period, SUM(revenue) as revenue, SUM(transactions) as transactions FROM (
            SELECT 
                $periodSql as period,
                SUM(grand_total) as revenue,
                COUNT(*) as transactions
            FROM invoices
            WHERE status NOT IN ('draft', 'cancelled')
            AND invoice_date BETWEEN ? AND ?
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

    // 2. Fetch Expenses
    $expPeriodSql = ($period === 'quarterly') 
        ? "CONCAT(YEAR(expense_date), '-Q', QUARTER(expense_date))" 
        : "DATE_FORMAT(expense_date, '$dateFormat')";

    $expenseQuery = "
        SELECT 
            $expPeriodSql as period,
            SUM(amount) as expense
        FROM expenses
        WHERE status IN ('approved', 'paid')
        AND expense_date BETWEEN ? AND ?
        GROUP BY period
    ";

    $stmt = $pdo->prepare($expenseQuery);
    $stmt->execute([$startDate, $endDate]);
    $expenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 3. Merge and Generate Continuity
    $data = [];
    $allKeys = array_unique(array_merge(array_keys($revenueData), array_keys($expenses)));
    sort($allKeys);
    
    // Safely remove empty keys if they exist
    $emptyIndex = array_search('', $allKeys);
    if ($emptyIndex !== false) {
        unset($allKeys[$emptyIndex]);
    }

    $prevRevenue = 0;
    foreach ($allKeys as $key) {
        $rev = floatval($revenueData[$key]['revenue'] ?? 0);
        $trans = intval($revenueData[$key]['transactions'] ?? 0);
        $exp = floatval($expenses[$key] ?? 0);
        
        // Format label for display
        $label = $key;
        if ($period === 'monthly') {
             $label = date('M Y', strtotime($key . '-01'));
        } elseif ($period === 'weekly') {
             // Split YYYY-WW
             $parts = explode('-', $key);
             $label = (count($parts) == 2) ? "Wk {$parts[1]}, {$parts[0]}" : "Wk " . $key;
        }

        $growth = $prevRevenue > 0 ? (($rev - $prevRevenue) / $prevRevenue) * 100 : 0;
        
        $data[] = [
            'period' => $label,
            'revenue' => $rev,
            'transactions' => $trans,
            'expense' => $exp,
            'growth' => round($growth, 1)
        ];
        $prevRevenue = $rev;
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log("Performance API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
