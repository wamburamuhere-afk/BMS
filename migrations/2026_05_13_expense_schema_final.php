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
    // Skip if type_id already exists (idempotent)
    $res = $pdo->query("SHOW COLUMNS FROM expense_categories LIKE 'type_id'");
    if (!$res->fetch()) {
        // Disable FK checks so dependent tables (e.g. expense_category_map) don't block the drop
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS expense_categories");
        $pdo->exec("CREATE TABLE expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (type_id) REFERENCES expense_types(id) ON DELETE CASCADE,
            UNIQUE KEY unique_type_cat (type_id, name)
        ) ENGINE=InnoDB");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        echo "✓ Table 'expense_categories' successfully recreated with 'type_id'.\n";
    } else {
        echo "✓ Table 'expense_categories' already has 'type_id' — skipped.\n";
    }

    // 3. Ensure expenses table has the right columns — guard if table absent on this server
    $tbl = $pdo->query("SHOW TABLES LIKE 'expenses'")->fetch();
    if (!$tbl) {
        echo "Table 'expenses' not found on this server — skipping column additions.\n";
    } else {
        $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'category_id'");
        if (!$res->fetch()) {
            $pdo->exec("ALTER TABLE expenses ADD COLUMN category_id INT NULL");
            echo "✓ Column 'category_id' added to 'expenses'.\n";
        } else {
            echo "✓ Column 'category_id' already exists.\n";
        }

        $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'type_id'");
        if (!$res->fetch()) {
            $pdo->exec("ALTER TABLE expenses ADD COLUMN type_id INT NULL");
            echo "✓ Column 'type_id' added to 'expenses'.\n";
        } else {
            echo "✓ Column 'type_id' already exists.\n";
        }
    }

    echo "Final Schema Fix Complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
