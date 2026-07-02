<?php
require_once 'roots.php';

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->query("DESCRIBE $table");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return in_array($column, $columns);
}

try {
    if (!columnExists($pdo, 'customers', 'logo_path')) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN logo_path VARCHAR(255) AFTER acronym");
        echo "Column logo_path added to customers.\n";
    } else {
        echo "Column logo_path already exists in customers.\n";
    }
    
    if (!columnExists($pdo, 'suppliers', 'logo_path')) {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN logo_path VARCHAR(255) AFTER acronym");
        echo "Column logo_path added to suppliers.\n";
    } else {
        echo "Column logo_path already exists in suppliers.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
