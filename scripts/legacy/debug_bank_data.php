<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT a.*, at.type_name, ac.category_name 
                    FROM accounts a 
                    LEFT JOIN account_types at ON a.account_type_id = at.type_id 
                    LEFT JOIN account_categories ac ON a.category_id = ac.category_id 
                    WHERE a.account_name LIKE '%Bank%' OR a.account_name LIKE '%Cash%' OR a.account_name LIKE '%CRDB%' OR a.account_name LIKE '%NMB%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
