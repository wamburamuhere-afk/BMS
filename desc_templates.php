<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE document_templates");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
