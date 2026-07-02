<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE pos_sale_items");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>
