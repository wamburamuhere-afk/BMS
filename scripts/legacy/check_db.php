<?php
require_once 'roots.php';
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables:\n" . implode("\n", $tables) . "\n\n";
    
    $check_tables = ['document_templates', 'template_categories', 'document_categories'];
    foreach ($check_tables as $table) {
        if (in_array($table, $tables)) {
            echo "Describe $table:\n";
            $cols = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                echo "  {$col['Field']} ({$col['Type']})\n";
            }
        } else {
            echo "$table DOES NOT EXIST\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
