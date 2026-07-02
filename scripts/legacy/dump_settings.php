<?php
require 'includes/config.php';
try {
    $stmt = $pdo->query('SELECT * FROM system_settings');
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo $e->getMessage();
}
