<?php
require_once 'includes/config.php';
global $pdo;

// 1. Create product_units table if not exists
$stmt = $pdo->query("SHOW TABLES LIKE 'product_units'");
if (!$stmt->fetch()) {
    $pdo->exec("CREATE TABLE product_units (
        unit_id INT AUTO_INCREMENT PRIMARY KEY,
        unit_code VARCHAR(20) UNIQUE NOT NULL,
        unit_name VARCHAR(50) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $units = [
        ['pcs', 'Pieces'], ['kg', 'Kilogram'], ['g', 'Gram'], ['l', 'Liter'], ['ml', 'Milliliter'],
        ['m', 'Meter'], ['cm', 'Centimeter'], ['pack', 'Pack'], ['box', 'Box'], ['carton', 'Carton'],
        ['bottle', 'Bottle'], ['can', 'Can'], ['bag', 'Bag'], ['pair', 'Pair'], ['set', 'Set'], ['dozen', 'Dozen']
    ];
    
    $stmt = $pdo->prepare("INSERT INTO product_units (unit_code, unit_name) VALUES (?, ?)");
    foreach ($units as $u) {
        try {
            $stmt->execute($u);
        } catch (Exception $e) {}
    }
}

// 2. Ensure at least one warehouse exists
try {
    $res = $pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
    if ($res == 0) {
        $pdo->exec("INSERT INTO warehouses (warehouse_name, status) VALUES ('Main Store', 'active')");
    }
} catch (Exception $e) {
    // If table doesn't exist, create it (basic version)
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (
        warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
        warehouse_name VARCHAR(100) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO warehouses (warehouse_name, status) VALUES ('Main Store', 'active')");
}
echo "Setup complete";
?>
