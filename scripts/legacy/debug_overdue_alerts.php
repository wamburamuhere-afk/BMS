<?php
require_once 'includes/config.php';
$stmt = $pdo->prepare("
    SELECT 
        invoice_id,
        invoice_number,
        grand_total,
        paid_amount,
        due_date
    FROM invoices 
    WHERE status IN ('sent', 'partial', 'pending')
    AND due_date < CURDATE()
    AND (grand_total - paid_amount) > 0
");
$stmt->execute();
$overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Overdue Invoices:\n";
foreach ($overdue as $inv) {
    echo "- #{$inv['invoice_number']}: Due={$inv['due_date']}, Balance=" . ($inv['grand_total'] - $inv['paid_amount']) . "\n";
}
