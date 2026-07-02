<?php
require_once 'roots.php';
global $pdo;

echo "--- Triggers ---\n";
$stmt = $pdo->query("SHOW TRIGGERS");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
