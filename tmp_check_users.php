<?php
require_once __DIR__ . '/roots.php';
global $pdo;

$table = 'users';
try {
    $stmt = $pdo->prepare("DESCRIBE $table");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in $table:\n";
    foreach ($columns as $column) {
        echo $column['Field'] . " (" . $column['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
