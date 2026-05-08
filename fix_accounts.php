<?php
require_once 'roots.php';
global $pdo;

echo "Fixing account types for Bank and Fixed Asset accounts...\n";

// Fix CRDB Bank - Main Account (currently ID 9, type 4)
$pdo->prepare("UPDATE accounts SET account_type_id = 1, account_type = 'asset' WHERE account_id = 9")->execute();
echo "Fixed Account ID 9\n";

// Fix Fixed Assets (currently ID 10, type 3)
$pdo->prepare("UPDATE accounts SET account_type_id = 1, account_type = 'asset' WHERE account_id = 10")->execute();
echo "Fixed Account ID 10\n";

// Just in case, fix CRDB (ID 8)
$pdo->prepare("UPDATE accounts SET account_type_id = 1, account_type = 'asset' WHERE account_id = 8")->execute();
echo "Fixed Account ID 8\n";
?>
