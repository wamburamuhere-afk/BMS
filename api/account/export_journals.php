<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    die('Unauthorized access');
}

// Fetch journals with totals
$stmt = $pdo->query("
    SELECT 
        je.entry_date,
        je.reference_number,
        je.description,
        je.status,
        u.username as created_by_name,
        SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END) as total_debits,
        SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END) as total_credits
    FROM journal_entries je
    LEFT JOIN journal_entry_items jei ON je.entry_id = jei.entry_id
    LEFT JOIN users u ON je.created_by = u.user_id
    GROUP BY je.entry_id
    ORDER BY je.entry_date DESC, je.created_at DESC
");
$journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="journals_export_' . date('Y-m-d') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, ['Date', 'Reference', 'Description', 'Total Debits', 'Total Credits', 'Status', 'Created By']);

// Add data
foreach ($journals as $row) {
    fputcsv($output, [
        $row['entry_date'],
        $row['reference_number'],
        $row['description'],
        number_format($row['total_debits'], 2, '.', ''),
        number_format($row['total_credits'], 2, '.', ''),
        ucfirst($row['status']),
        $row['created_by_name']
    ]);
}

fclose($output);
exit;
