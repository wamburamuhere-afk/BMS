<?php
require_once 'roots.php';
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT s.supplier_id, s.supplier_name, COUNT(sp.payment_id) as p_count FROM suppliers s LEFT JOIN supplier_payments sp ON s.supplier_id = sp.supplier_id GROUP BY s.supplier_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
