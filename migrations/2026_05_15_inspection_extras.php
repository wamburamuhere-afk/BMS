<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: inspection extras (inspectors, attachments, new columns)...\n";

try {
    // 1. Add sub_milestone_id to project_inspections
    $res = $pdo->query("SHOW COLUMNS FROM project_inspections LIKE 'sub_milestone_id'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE project_inspections ADD COLUMN sub_milestone_id INT NULL AFTER milestone_id");
        echo "✓ Column 'sub_milestone_id' added to 'project_inspections'.\n";
    } else {
        echo "✓ Column 'sub_milestone_id' already exists — skipped.\n";
    }

    // 2. Add inspected_scope to project_inspections
    $res = $pdo->query("SHOW COLUMNS FROM project_inspections LIKE 'inspected_scope'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE project_inspections ADD COLUMN inspected_scope DECIMAL(15,2) NULL AFTER sub_milestone_id");
        echo "✓ Column 'inspected_scope' added to 'project_inspections'.\n";
    } else {
        echo "✓ Column 'inspected_scope' already exists — skipped.\n";
    }

    // 3. Create inspection_inspectors table
    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_inspectors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inspection_id INT NOT NULL,
        inspector_name VARCHAR(150) NOT NULL,
        inspector_org VARCHAR(150) NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (inspection_id) REFERENCES project_inspections(inspection_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    echo "✓ Table 'inspection_inspectors' ensured.\n";

    // 4. Create inspection_attachments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inspection_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(50) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        uploaded_by INT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (inspection_id) REFERENCES project_inspections(inspection_id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    echo "✓ Table 'inspection_attachments' ensured.\n";

    // 5. Create uploads/inspections directory
    $dir = __DIR__ . '/../uploads/inspections';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "✓ Directory 'uploads/inspections' created.\n";
    } else {
        echo "✓ Directory 'uploads/inspections' already exists — skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
