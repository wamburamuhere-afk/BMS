<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE documents");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
