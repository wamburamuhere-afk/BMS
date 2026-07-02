<?php
require_once 'roots.php';
$stmt = $pdo->query("SHOW TABLES LIKE 'document_templates'");
$exists = $stmt->fetch();
if ($exists) {
    echo "Table 'document_templates' exists.\n";
    $stmt = $pdo->query("DESCRIBE document_templates");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
} else {
    echo "Table 'document_templates' DOES NOT EXIST.\n";
}
?>
