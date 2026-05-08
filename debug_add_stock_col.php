<?php
require_once 'roots.php';
global $pdo;
$pdo->exec("ALTER TABLE purchase_returns ADD COLUMN stock_updated TINYINT(1) DEFAULT 0 AFTER status");
echo "Column stock_updated added to purchase_returns\n";
