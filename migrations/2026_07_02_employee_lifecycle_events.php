<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create employee_lifecycle_events table + uploads/lifecycle dir...\n";

try {
    // 1. The single lifecycle-events table (Tier 1 D1 — one table for all event types).
    //    No FKs to designations/departments/projects: old/new ids are historical
    //    snapshots resolved via LEFT JOIN, and those rows may be soft-deleted later.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_lifecycle_events (
            event_id            INT AUTO_INCREMENT PRIMARY KEY,
            employee_id         INT NOT NULL,
            event_type          ENUM('promotion','demotion','transfer','award','warning',
                                     'complaint','resignation','termination') NOT NULL,
            event_date          DATE NOT NULL,
            end_date            DATE NULL,
            title               VARCHAR(255) NOT NULL,
            description         TEXT NULL,
            old_designation_id  INT NULL,
            new_designation_id  INT NULL,
            old_salary          DECIMAL(15,2) NULL,
            new_salary          DECIMAL(15,2) NULL,
            old_department_id   INT NULL,
            new_department_id   INT NULL,
            old_project_id      INT NULL,
            new_project_id      INT NULL,
            award_type          VARCHAR(100) NULL,
            award_gift          VARCHAR(255) NULL,
            award_amount        DECIMAL(15,2) NULL,
            severity            ENUM('verbal','written','final') NULL,
            complainant         VARCHAR(255) NULL,
            resolution          TEXT NULL,
            termination_type    VARCHAR(100) NULL,
            notice_date         DATE NULL,
            status              ENUM('pending','approved','rejected','cancelled','deleted')
                                NOT NULL DEFAULT 'pending',
            approved_by         INT NULL,
            approved_at         DATETIME NULL,
            reject_reason       VARCHAR(500) NULL,
            effect_applied_at   DATETIME NULL,
            attachment_path     VARCHAR(500) NULL,
            attachment_name     VARCHAR(255) NULL,
            created_by          INT NOT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by          INT NULL,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_emp_type (employee_id, event_type, status),
            KEY idx_status_date (status, event_date),
            CONSTRAINT fk_ele_employee FOREIGN KEY (employee_id)
                REFERENCES employees(employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "Table employee_lifecycle_events ready.\n";

    // 2. Attachment upload directory + deny-exec .htaccess (security template §19)
    $uploadDir = __DIR__ . '/../uploads/lifecycle';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo "Migration failed: could not create uploads/lifecycle directory.\n";
            exit(1);
        }
        echo "Created uploads/lifecycle directory.\n";
    } else {
        echo "uploads/lifecycle directory already exists.\n";
    }

    $htaccess = $uploadDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = "<FilesMatch \"\\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
               . "    Require all denied\n"
               . "</FilesMatch>\n"
               . "Options -ExecCGI\n"
               . "RemoveHandler .php .phtml .php5\n"
               . "RemoveType .php .phtml .php5\n";
        if (file_put_contents($htaccess, $rules) === false) {
            echo "Migration failed: could not write uploads/lifecycle/.htaccess.\n";
            exit(1);
        }
        echo "Wrote uploads/lifecycle/.htaccess.\n";
    } else {
        echo "uploads/lifecycle/.htaccess already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
