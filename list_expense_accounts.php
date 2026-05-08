<?php
require_once 'roots.php';
$accounts = $pdo->query("SELECT a.account_id, a.account_name, a.status, t.type_name 
                        FROM accounts a 
                        JOIN account_types t ON a.account_type_id = t.type_id 
                        WHERE t.type_name LIKE '%expense%'")->fetchAll(PDO::FETCH_ASSOC);
echo "### EXPENSE ACCOUNTS ###\n";
print_r($accounts);
?>
