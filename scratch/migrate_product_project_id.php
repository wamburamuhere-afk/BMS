<?php
require __DIR__ . '/../roots.php';
global $pdo;

try {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('project_id', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN project_id INT NULL DEFAULT NULL AFTER warehouse_id");
        echo "Added column: products.project_id\n";
    } else {
        echo "Column products.project_id already exists.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
