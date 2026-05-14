<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

$tables = ['expenses', 'expense_types', 'expense_categories', 'expense_category_map'];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        echo "Table $table exists. Columns:\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo " - " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } catch (Exception $e) {
        echo "Table $table DOES NOT EXIST or error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
