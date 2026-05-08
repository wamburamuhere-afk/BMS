<?php
require_once 'roots.php';
$accounts = $pdo->query("SELECT a.account_id, a.account_name, t.type_name FROM accounts a JOIN account_types t ON a.account_type_id = t.type_id")->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts);
?>
