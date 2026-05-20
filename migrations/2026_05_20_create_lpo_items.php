<?php
require_once __DIR__ . '/../roots.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_lpo_items (
        item_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lpo_id       INT UNSIGNED NOT NULL,
        sort_order   INT NOT NULL DEFAULT 1,
        product_name VARCHAR(255) NOT NULL,
        quantity     DECIMAL(10,3) NOT NULL DEFAULT 1.000,
        unit_price   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        tax_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
        total        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lpo_items_lpo_id (lpo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Migration 2026_05_20_create_lpo_items: customer_lpo_items table created OK\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
