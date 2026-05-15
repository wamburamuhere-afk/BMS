<?php
/**
 * Migration: Expense Schema Enhancement
 * Date: 2026-05-13
 * Sub-Phase 1.1: Create Dynamic Types and Categories
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    $pdo->beginTransaction();

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

    // 3. Update expenses table to include references
    // Check if columns exist first
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

    $pdo->commit();
    echo "Migration Phase 1.1 completed successfully.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
