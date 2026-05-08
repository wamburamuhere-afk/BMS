<?php
require_once 'includes/config.php';

try {
    // Add warehouse_id to purchase_orders
    $check_po = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'warehouse_id'");
    if (!$check_po->fetch()) {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN warehouse_id INT AFTER project_id");
        echo "Added warehouse_id to purchase_orders\n";
    } else {
        echo "warehouse_id already exists in purchase_orders\n";
    }

    // Add warehouse_id to sales_orders
    $check_so = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'warehouse_id'");
    if (!$check_so->fetch()) {
        $pdo->exec("ALTER TABLE sales_orders ADD COLUMN warehouse_id INT AFTER project_id");
        echo "Added warehouse_id to sales_orders\n";
    } else {
        echo "warehouse_id already exists in sales_orders\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
