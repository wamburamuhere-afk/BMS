<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create customer_lpos table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_lpos (
            lpo_id        INT AUTO_INCREMENT PRIMARY KEY,
            lpo_number    VARCHAR(100)    NOT NULL,
            customer_id   INT             NOT NULL,
            issue_date    DATE            NOT NULL,
            expiry_date   DATE            NULL,
            amount        DECIMAL(15,2)   NOT NULL DEFAULT 0,
            currency      VARCHAR(10)     NOT NULL DEFAULT 'TZS',
            description   TEXT            NULL,
            status        ENUM('open','partially_fulfilled','fulfilled','cancelled') NOT NULL DEFAULT 'open',
            document_path VARCHAR(255)    NULL,
            notes         TEXT            NULL,
            created_by    INT             NOT NULL,
            created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_id (customer_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Table customer_lpos created (or already exists).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
