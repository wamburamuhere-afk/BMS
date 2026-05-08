<?php
require_once 'roots.php';
$types = $pdo->query("SELECT * FROM account_types")->fetchAll(PDO::FETCH_ASSOC);
echo "### ACCOUNT TYPES ###\n";
print_r($types);

$accounts = $pdo->query("SELECT a.*, t.type_name FROM accounts a JOIN account_types t ON a.account_type_id = t.type_id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "\n### ACCOUNTS ###\n";
print_r($accounts);
?>
