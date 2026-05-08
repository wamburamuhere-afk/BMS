<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT a.account_name, a.account_code, at.type_name, ac.category_name 
                    FROM accounts a 
                    LEFT JOIN account_types at ON a.account_type_id = at.type_id 
                    LEFT JOIN account_categories ac ON a.category_id = ac.category_id");
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($all as $row) {
    echo implode(" | ", $row) . PHP_EOL;
}
