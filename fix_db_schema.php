<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: text/plain');
echo "Starting Database Schema Sync...\n";

try {
    // 1. Ensure expense_types table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "✓ Table 'expense_types' ready.\n";

    // 2. Ensure expense_categories table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (type_id) REFERENCES expense_types(id) ON DELETE CASCADE,
        UNIQUE KEY unique_type_cat (type_id, name)
    ) ENGINE=InnoDB;");
    echo "✓ Table 'expense_categories' ready.\n";

    // 3. Ensure expense_category_map table exists (This was likely missing!)
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_category_map (
        expense_id INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (expense_id, category_id),
        FOREIGN KEY (expense_id) REFERENCES expenses(expense_id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
    echo "✓ Table 'expense_category_map' ready.\n";

    // 4. Ensure expenses table has required columns
    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'type_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN type_id INT NULL AFTER expense_account_id");
        echo "✓ Column 'type_id' added to 'expenses'.\n";
    }

    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'paid_to_type'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN paid_to_type ENUM('supplier', 'staff', 'sub_contractor') DEFAULT NULL AFTER vendor");
        echo "✓ Column 'paid_to_type' added to 'expenses'.\n";
    }

    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'paid_to_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN paid_to_id INT DEFAULT NULL AFTER paid_to_type");
        echo "✓ Column 'paid_to_id' added to 'expenses'.\n";
    }

    echo "\nSchema sync completed successfully.\n";

} catch (Exception $e) {
    echo "\nERROR DURING SYNC: " . $e->getMessage() . "\n";
}
