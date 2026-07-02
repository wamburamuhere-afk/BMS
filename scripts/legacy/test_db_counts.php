<?php
require_once __DIR__ . '/includes/config.php';
global $pdo;
$company_type = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'")->fetchColumn();
echo "Company Type: " . $company_type . "\n";

$invoices_count = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
echo "Invoices Count: " . $invoices_count . "\n";

$paid_invoices_count = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'paid'")->fetchColumn();
echo "Paid Invoices Count: " . $paid_invoices_count . "\n";

$pos_sales_count = $pdo->query("SELECT COUNT(*) FROM pos_sales")->fetchColumn();
echo "POS Sales Count: " . $pos_sales_count . "\n";

$repayments_count = $pdo->query("SELECT COUNT(*) FROM loan_repayments")->fetchColumn();
echo "Repayments Count: " . $repayments_count . "\n";
?>
