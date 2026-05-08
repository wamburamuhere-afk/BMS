<?php
require_once 'roots.php';
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables:\n";
print_r($tables);

if (in_array('expenses', $tables)) {
    echo "\nExpenses Schema:\n";
    $cols = $pdo->query("DESCRIBE expenses")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
}
?>
