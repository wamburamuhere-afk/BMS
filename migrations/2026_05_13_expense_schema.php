<?php
/**
 * Migration: Expense Schema Enhancement
 * Date: 2026-05-13
 * Sub-Phase 1.1: Create Dynamic Types and Categories
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    echo "Starting Migration Phase 1.1...\n";

    // 1. Create expense_types table
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");
    echo "✓ Table 'expense_types' created.\n";

    // 2. Create expense_categories table
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
    echo "✓ Table 'expense_categories' created.\n";

    // 3. Update expenses table — guard: skip entirely if table doesn't exist on this server
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

    echo "Migration Phase 1.1 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
