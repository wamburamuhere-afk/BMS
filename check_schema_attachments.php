<?php
require_once 'roots.php';
require_once CONFIG_FILE;

try {
    $stmt = $pdo->query("DESCRIBE customer_additional_attachments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($columns, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo $e->getMessage();
}
