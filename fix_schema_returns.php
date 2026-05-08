<?php
require_once __DIR__ . '/roots.php';
global $pdo;

try {
    // Add sales_order_id column
    $pdo->exec("ALTER TABLE sales_returns ADD COLUMN sales_order_id INT NULL AFTER invoice_id");
    echo "Added sales_order_id column.\n";
} catch (Exception $e) {
    echo "sales_order_id might already exist or error: " . $e->getMessage() . "\n";
}

try {
    // Add items table if not exists - wait, checking if sales_return_items exists?
    // User error was about sales_order_id in field list, which implies insertion into sales_returns.
    // But what about items table?
    $stmt = $pdo->query("DESCRIBE sales_return_items");
    echo "sales_return_items exists.\n";
} catch (Exception $e) {
    echo "sales_return_items does not exist. Creating it.\n";
    // Create my version of items table if it doesn't exist
     $pdo->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
        return_item_id INT AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        unit_price DECIMAL(15,2) NOT NULL,
        line_total DECIMAL(15,2) NOT NULL,
        condition_note VARCHAR(255),
        FOREIGN KEY (return_id) REFERENCES sales_returns(sales_return_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(product_id)
    )");
}

?>
