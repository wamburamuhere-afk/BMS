<?php
require_once 'includes/config.php';
$stmt = $pdo->prepare("SELECT invoice_number, status FROM invoices WHERE invoice_number IN ('INV-20260123-783', 'INV-20260123-2769', 'INV-20260123-5966')");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
