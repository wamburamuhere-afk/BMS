<?php
require 'roots.php';
global $pdo;

function describeTable($tableName) {
    global $pdo;
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        echo "Table: $tableName\n";
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode($row) . "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

describeTable('employees');
