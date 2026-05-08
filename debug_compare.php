<?php
require_once 'roots.php';
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT s.supplier_name, 
    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.supplier_id) as po_count,
    (SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = s.supplier_id) as p_count 
    FROM suppliers s");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
