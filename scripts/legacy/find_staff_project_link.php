<?php
require 'roots.php';
global $pdo;
$stmt = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME = 'employee_id' AND TABLE_NAME IN (SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME = 'project_id')");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . "\n";
}
echo "Done.\n";
