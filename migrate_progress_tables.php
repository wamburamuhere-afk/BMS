<?php
// migrate_progress_tables.php
require_once __DIR__ . '/roots.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_milestones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            description VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            scope DECIMAL(15,2) NOT NULL,
            weight_percent DECIMAL(5,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_progress_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            report_date DATE NOT NULL,
            report_type ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual') DEFAULT 'daily',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_progress_report_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            milestone_id INT NOT NULL,
            actual_value DECIMAL(15,2) NOT NULL,
            progress_percent DECIMAL(5,2) NOT NULL, -- (actual/scope) * weight_percent
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (report_id) REFERENCES project_progress_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (milestone_id) REFERENCES project_milestones(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    echo "Progress tables created successfully!\n";
} catch (Exception $e) {
    echo "Error creating tables: " . $e->getMessage() . "\n";
}
