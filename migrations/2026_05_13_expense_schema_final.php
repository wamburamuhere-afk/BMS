<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Forcing Schema Alignment for Phase 1.1...\n";

try {
    // 1. Recreate expense_types (ensure it's correct)
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

    // 2. Drop and Recreate expense_categories with correct type_id
    // We drop it because the existing schema is incompatible (wrong PK name, missing FK)
    $pdo->exec("DROP TABLE IF EXISTS expense_categories;");
    
    $pdo->exec("CREATE TABLE expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (type_id) REFERENCES expense_types(id) ON DELETE CASCADE,
        UNIQUE KEY unique_type_cat (type_id, name)
    ) ENGINE=InnoDB;");
    echo "✓ Table 'expense_categories' successfully recreated with 'type_id'.\n";

    // 3. Ensure expenses table has the right columns
    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'category_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN category_id INT NULL AFTER expense_account_id");
        echo "✓ Column 'category_id' added to 'expenses'.\n";
    }

    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'type_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN type_id INT NULL AFTER category_id");
        echo "✓ Column 'type_id' added to 'expenses'.\n";
    }

    echo "Final Schema Fix Complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
