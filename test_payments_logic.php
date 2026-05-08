<?php
require_once 'roots.php';
require_once 'includes/config.php';

$supplier_id = 2; // "Fine"
$query = "
    SELECT sp.*,
           s.supplier_name, s.company_name,
           po.order_number,
           u.username as created_by_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.supplier_id
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
    LEFT JOIN users u ON sp.created_by = u.user_id
    WHERE sp.supplier_id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$supplier_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total payments for ID 2: " . count($payments) . "\n";
foreach ($payments as $p) {
    echo "ID: {$p['payment_id']}, Date: {$p['payment_date']}, Amount: {$p['amount']}\n";
}

$this_month = date('Y-m');
$this_month_payments = array_filter($payments, function($payment) use ($this_month) {
    return date('Y-m', strtotime($payment['payment_date'])) == $this_month;
});
echo "This month payments: " . count($this_month_payments) . "\n";
