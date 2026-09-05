<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tender AWARDED -> Project linkage hardening...\n";

try {
    // tender_id: traceability (tender.md Sec 2.1 gap #1) + the idempotency
    // guard's actual DB-level enforcement (gap #4) via the UNIQUE key — a
    // second award attempt for the same tender fails here even if the PHP
    // status guard is ever bypassed.
    $hasTenderId = $pdo->query("SHOW COLUMNS FROM projects LIKE 'tender_id'")->fetch();
    if (!$hasTenderId) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN tender_id INT NULL DEFAULT NULL");
        echo "  - projects.tender_id added.\n";
    } else {
        echo "  - projects.tender_id already present, skipped.\n";
    }
    $hasUnique = $pdo->query("SHOW INDEX FROM projects WHERE Key_name = 'uniq_project_tender'")->fetch();
    if (!$hasUnique) {
        $pdo->exec("ALTER TABLE projects ADD UNIQUE KEY uniq_project_tender (tender_id)");
        echo "  - projects.uniq_project_tender unique key added.\n";
    } else {
        echo "  - projects.uniq_project_tender already present, skipped.\n";
    }

    // budget_currency: gap #5 — an awarded USD tender must not silently
    // become an unlabeled figure that TZS-based reporting misreads.
    $hasCurrency = $pdo->query("SHOW COLUMNS FROM projects LIKE 'budget_currency'")->fetch();
    if (!$hasCurrency) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN budget_currency VARCHAR(3) NOT NULL DEFAULT 'TZS'");
        echo "  - projects.budget_currency added.\n";
    } else {
        echo "  - projects.budget_currency already present, skipped.\n";
    }

    // BOQ carry-over tables (gap #6 / Phase E data carry-over). Deliberately
    // separate from tender_boq_bills/items, not a reference to them — the
    // tender's BOQ stays frozen as submitted evidence even if project costing
    // changes later.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_boq_bills (
            bill_id INT NOT NULL AUTO_INCREMENT,
            project_id INT NOT NULL,
            bill_title VARCHAR(255) NOT NULL DEFAULT 'Bill No. 1 - General',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bill_id),
            KEY idx_pbb_project (project_id),
            CONSTRAINT fk_pbb_project FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - project_boq_bills ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_boq_items (
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
            KEY idx_pbi_bill (bill_id),
            CONSTRAINT fk_pbi_bill FOREIGN KEY (bill_id) REFERENCES project_boq_bills(bill_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - project_boq_items ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
