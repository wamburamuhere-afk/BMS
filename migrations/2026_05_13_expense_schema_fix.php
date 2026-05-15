<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Completing Migration Phase 1.1...\n";

try {
    // Ensure tables are fully set up
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;");

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

    // Add missing category_id column
    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'category_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN category_id INT NULL AFTER expense_account_id");
        echo "✓ Column 'category_id' added to 'expenses'.\n";
    } else {
        echo "✓ Column 'category_id' already exists.\n";
    }

    // Ensure type_id exists
    $res = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'type_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN type_id INT NULL AFTER category_id");
        echo "✓ Column 'type_id' added to 'expenses'.\n";
    } else {
        echo "✓ Column 'type_id' already exists.\n";
    }

    echo "Migration Phase 1.1 is now 100% complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
