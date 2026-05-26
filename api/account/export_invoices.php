<?php
// Disable error reporting for cleaner output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../includes/config.php';

// Clean output buffer to remove any HTML from includes
if (ob_get_length()) ob_clean();

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="invoices_export_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

// Filter logic — scope: user's assigned projects + unassigned invoices
$where = "1=1" . scopeFilterSqlNullable('project', 'i');
$params = [];

// Status Filter
if (!empty($_GET['status'])) {
    $where .= " AND i.status = ?";
    $params[] = $_GET['status'];
}

// Payment Status Filter
if (!empty($_GET['payment_status'])) {
    $ps = $_GET['payment_status'];
    if ($ps == 'paid') {
        $where .= " AND (i.status = 'paid' OR i.paid_amount >= i.grand_total)";
    } elseif ($ps == 'partial') {
        $where .= " AND (i.status = 'partial' OR (i.paid_amount > 0 AND i.paid_amount < i.grand_total))";
    } elseif ($ps == 'unpaid') {
        $where .= " AND (i.paid_amount = 0 AND i.status != 'paid')";
    } elseif ($ps == 'overdue') {
        $where .= " AND (i.status = 'overdue' OR (i.status != 'paid' AND i.due_date < CURDATE()))";
    } elseif ($ps == 'pending') {
        $where .= " AND i.status IN ('pending', 'sent', 'partial')";
    } elseif (in_array($ps, ['draft', 'sent', 'cancelled'])) {
        $where .= " AND i.status = ?";
        $params[] = $ps;
    }
}

// Customer Filter
if (!empty($_GET['customer'])) {
    $where .= " AND i.customer_id = ?";
    $params[] = $_GET['customer'];
}

// Date Range Filter
if (!empty($_GET['date_from'])) {
    $where .= " AND i.invoice_date >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where .= " AND i.invoice_date <= ?";
    $params[] = $_GET['date_to'];
}

// Security: Ensure users only see their own data if restrictions apply
// Assuming access control is handled via session/permissions check at the top (which should be added)
// For now, assuming admin/authorized access as per existing file structure

try {
    $sql = "
        SELECT 
            i.invoice_number,
            i.invoice_date,
            i.due_date,
            c.customer_name,
            c.company_name,
            i.grand_total,
            i.paid_amount,
            (i.grand_total - i.paid_amount) as balance_due,
            i.status,
            i.currency
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE $where 
        ORDER BY i.invoice_date DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output Header Row
    echo "Invoice #\tDate\tDue Date\tCustomer\tCompany\tTotal Amount\tPaid Amount\tBalance Due\tStatus\tCurrency\n";

    // Output Data Rows
    foreach ($invoices as $inv) {
        // Clean data for tab-delimited format (remove tabs and newlines)
        $clean_inv = array_map(function($val) {
            return str_replace(["\t", "\n", "\r"], " ", $val);
        }, $inv);

        echo implode("\t", $clean_inv) . "\n";
    }

} catch (PDOException $e) {
    echo "Error exporting data";
}
exit();
