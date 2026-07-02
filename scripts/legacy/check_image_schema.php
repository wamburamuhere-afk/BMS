<?php
require_once 'roots.php';
$tables = ['customers', 'suppliers'];
foreach ($tables as $table) {
    echo "Table: $table\n";
    $stmt = $pdo->query("DESCRIBE $table");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($columns, JSON_PRETTY_PRINT) . "\n\n";
}
?>
