<?php
require_once 'roots.php';
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT order_number, supplier_id, total_amount, paid_amount, payment_status FROM purchase_orders WHERE paid_amount > 0");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
