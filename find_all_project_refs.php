<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME IN ('project_id', 'proj_id')");
echo "Tables with project_id / proj_id:\n";
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . " (" . $row['COLUMN_NAME'] . ")\n";
}
?>
