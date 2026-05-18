<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create supplier_projects table...\n";

try {
    $col = $pdo->query("SHOW TABLES LIKE 'supplier_projects'")->fetch();
    if (!$col) {
        $pdo->exec("
            CREATE TABLE supplier_projects (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                project_id  INT NOT NULL,
                assigned_by INT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_assignment (supplier_id, project_id)
            ) ENGINE=InnoDB
        ");
        echo "Table supplier_projects created.\n";

        // Seed existing project associations derived from purchase orders
        $pdo->exec("
            INSERT IGNORE INTO supplier_projects (supplier_id, project_id, assigned_by, assigned_at)
            SELECT DISTINCT po.supplier_id, po.project_id, po.created_by, po.created_at
            FROM purchase_orders po
            WHERE po.project_id IS NOT NULL AND po.supplier_id IS NOT NULL
        ");
        echo "Existing PO-based project associations seeded.\n";
    } else {
        echo "Table supplier_projects already exists, skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
