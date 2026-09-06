<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/tender_checklist.php';
global $pdo;

echo "Starting migration: tender PPRA compliance checklist...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tender_checklist_items (
            item_id INT NOT NULL AUTO_INCREMENT,
            tender_id INT NOT NULL,
            item_text VARCHAR(255) NOT NULL,
            is_ready TINYINT(1) NOT NULL DEFAULT 0,
            is_custom TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            KEY idx_tci_tender (tender_id),
            CONSTRAINT fk_tci_tender FOREIGN KEY (tender_id) REFERENCES tenders(tender_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - tender_checklist_items ready.\n";

    // Back-fill: seed the standard 19 items for every tender that predates
    // this migration and has no checklist rows yet, so existing tenders get
    // the same compliance checklist as new ones instead of showing "0 / 0".
    $existingTenders = $pdo->query("
        SELECT t.tender_id FROM tenders t
        LEFT JOIN tender_checklist_items c ON c.tender_id = t.tender_id
        WHERE c.item_id IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($existingTenders as $tenderId) {
        seedTenderChecklist($pdo, (int)$tenderId);
    }
    echo "  - backfilled checklist for " . count($existingTenders) . " existing tender(s).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
