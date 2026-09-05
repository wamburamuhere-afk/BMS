<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tender Materials Schedule...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tender_materials (
            material_id INT NOT NULL AUTO_INCREMENT,
            tender_id INT NOT NULL,
            product_id INT NULL DEFAULT NULL,
            material VARCHAR(255) NOT NULL,
            specification VARCHAR(255) DEFAULT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            rate DECIMAL(15,2) NOT NULL DEFAULT 0,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (material_id),
            KEY idx_tm_tender (tender_id),
            KEY idx_tm_product (product_id),
            CONSTRAINT fk_tm_tender FOREIGN KEY (tender_id) REFERENCES tenders(tender_id) ON DELETE CASCADE,
            CONSTRAINT fk_tm_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - tender_materials ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
