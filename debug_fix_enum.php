<?php
require_once 'roots.php';
global $pdo;

$sql = "ALTER TABLE purchase_returns MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT 'pending'";
$pdo->exec($sql);
echo "Status column updated for purchase_returns table.\n";
