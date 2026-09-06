<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tender Bills of Quantities (BOQ) engine...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tender_boq_bills (
            bill_id INT NOT NULL AUTO_INCREMENT,
            tender_id INT NOT NULL,
            bill_title VARCHAR(255) NOT NULL DEFAULT 'Bill No. 1 - General',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bill_id),
            KEY idx_tbb_tender (tender_id),
            CONSTRAINT fk_tbb_tender FOREIGN KEY (tender_id) REFERENCES tenders(tender_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - tender_boq_bills ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tender_boq_items (
            item_id INT NOT NULL AUTO_INCREMENT,
            bill_id INT NOT NULL,
            description TEXT NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            rate DECIMAL(15,2) NOT NULL DEFAULT 0,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY idx_tbi_bill (bill_id),
            CONSTRAINT fk_tbi_bill FOREIGN KEY (bill_id) REFERENCES tender_boq_bills(bill_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - tender_boq_items ready.\n";

    $cols = [
        'boq_contingency_percent' => "DECIMAL(5,2) NOT NULL DEFAULT 0",
        'boq_vat_percent'         => "DECIMAL(5,2) NOT NULL DEFAULT 18",
        'boq_grand_total'         => "DECIMAL(18,2) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $col => $def) {
        $check = $pdo->query("SHOW COLUMNS FROM tenders LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE tenders ADD COLUMN $col $def");
            echo "  - tenders.$col added.\n";
        } else {
            echo "  - tenders.$col already present, skipped.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
