<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("
    SELECT t1.TABLE_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS t1
    JOIN INFORMATION_SCHEMA.COLUMNS t2 ON t1.TABLE_NAME = t2.TABLE_NAME
    WHERE t1.COLUMN_NAME = 'project_id' 
    AND t2.COLUMN_NAME = 'employee_id'
");
echo "Tables with BOTH project_id and employee_id:\n";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . "\n";
}

$stmt = $pdo->query("
    SELECT t1.TABLE_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS t1
    JOIN INFORMATION_SCHEMA.COLUMNS t2 ON t1.TABLE_NAME = t2.TABLE_NAME
    WHERE t1.COLUMN_NAME = 'proj_id' 
    AND t2.COLUMN_NAME = 'employee_id'
");
echo "\nTables with BOTH proj_id and employee_id:\n";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . "\n";
}
?>
