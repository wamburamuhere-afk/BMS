<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME IN ('staff_id', 'employee_id') AND TABLE_NAME IN (SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME IN ('project_id', 'proj_id'))");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . "\n";
}
echo "Done.\n";
