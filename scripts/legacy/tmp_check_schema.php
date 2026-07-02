<?php
require_once __DIR__ . '/roots.php';
global $pdo;
$tables = ['tenders', 'projects'];
$schema = [];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $schema[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $schema[$table] = "Error: " . $e->getMessage();
    }
}
echo json_encode($schema, JSON_PRETTY_PRINT);
?>
