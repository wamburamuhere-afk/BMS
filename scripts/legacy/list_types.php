<?php
require_once 'roots.php';
$types = $pdo->query("SELECT * FROM account_types")->fetchAll(PDO::FETCH_ASSOC);
foreach($types as $t) {
    echo "ID: {$t['type_id']} | Name: {$t['type_name']}\n";
}
?>
