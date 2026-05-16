<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: SC project context — sc_id on progress reports, sc_payments table...\n";

try {
    // Add sc_id to project_progress_reports so SC-mode reports are tagged
    $hasPPR = $pdo->query("SHOW TABLES LIKE 'project_progress_reports'")->fetch();
    if (!$hasPPR) {
        echo "Table 'project_progress_reports' not found on this server — skipping sc_id column.\n";
    } else {
        $col = $pdo->query("SHOW COLUMNS FROM project_progress_reports LIKE 'sc_id'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE project_progress_reports ADD COLUMN sc_id INT NULL DEFAULT NULL AFTER project_id");
            echo "Added sc_id to project_progress_reports.\n";
        } else {
            echo "sc_id already exists in project_progress_reports — skipped.\n";
        }
    }

    // Create dedicated sc_payments table (separate from supplier_payments)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sc_payments (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id     INT NOT NULL COMMENT 'sub_contractors.supplier_id',
            project_id      INT NOT NULL,
            payment_date    DATE NOT NULL,
            amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            currency        VARCHAR(10) NOT NULL DEFAULT 'TZS',
            payment_method  ENUM('cash','bank_transfer','cheque','mobile_money','other') NOT NULL,
            reference_number VARCHAR(150) NULL,
            receipt_number  VARCHAR(150) NULL COMMENT 'Receipt from sub-contractor',
            notes           TEXT NULL,
            status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
            created_by      INT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sc_project (supplier_id, project_id),
            INDEX idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "sc_payments table ready.\n";

    // Undo accidental project_id column on supplier_payments if it was added in a previous run
    $hasSP = $pdo->query("SHOW TABLES LIKE 'supplier_payments'")->fetch();
    if ($hasSP) {
        $col2 = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'project_id'")->fetchAll();
        if (!empty($col2)) {
            $pdo->exec("ALTER TABLE supplier_payments DROP COLUMN project_id");
            echo "Removed accidental project_id from supplier_payments.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
