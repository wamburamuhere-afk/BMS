<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE product_stocks");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
