<?php
// File: export_income_statement.php
require_once __DIR__ . '/../../roots.php';

// Check permissions
if (!isAuthenticated() || !hasAnyPermission('reports')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// This is a placeholder for actual Excel export logic
// In a real scenario, you'd use a library like PhpSpreadsheet
// For now, let's just output a CSV

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="income_statement_' . $start_date . '_to_' . $end_date . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Income Statement', $start_date, 'to', $end_date]);
fputcsv($output, []);
fputcsv($output, ['Category', 'Account', 'Amount']);

// You would fetch data here similar to get_income_statement.php
// ...

fclose($output);
exit;
?>
