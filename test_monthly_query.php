<?php
require_once 'includes/config.php';
$startDate = date('Y-m-d', strtotime('-6 months'));
$endDate = date('Y-m-d');
$dateFormat = '%Y-%m';

$revenueQuery = "
    SELECT period, SUM(revenue) as revenue, SUM(transactions) as transactions FROM (
        SELECT 
            DATE_FORMAT(invoice_date, '$dateFormat') as period,
            SUM(grand_total) as revenue,
            COUNT(*) as transactions
        FROM invoices
        WHERE status NOT IN ('draft', 'cancelled')
        AND invoice_date BETWEEN ? AND ?
        GROUP BY period
        UNION ALL
        SELECT 
            DATE_FORMAT(sale_date, '$dateFormat') as period,
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
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
