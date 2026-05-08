<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE stock_movements");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
