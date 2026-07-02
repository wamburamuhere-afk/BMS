<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE projects");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
