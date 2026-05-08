<?php
require_once 'roots.php';
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables:\n";
foreach($tables as $table) {
    echo $table . "\n";
}
?>
