<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create supplier_invoices table...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'supplier_invoices'")->fetch();
    if (!$table) {
        $pdo->exec("
            CREATE TABLE supplier_invoices (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                invoice_type     ENUM('supplier','sub_contractor') NOT NULL,
                supplier_id      INT NOT NULL,
                invoice_ref      VARCHAR(100) NOT NULL,
                date_raised      DATE NOT NULL,
                date_recorded    DATE NOT NULL,
                po_id            INT NULL,
                project_id       INT NULL,
                sc_invoice_basis ENUM('IPC','Milestone','Scope','Final') NULL,
                sc_basis_ref     VARCHAR(100) NULL,
                amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
                attachment       VARCHAR(255) NULL,
                status           ENUM('draft','submitted','approved','paid','deleted') NOT NULL DEFAULT 'draft',
                notes            TEXT NULL,
                recorded_by      INT NOT NULL,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_supplier   (supplier_id),
                INDEX idx_po         (po_id),
                INDEX idx_project    (project_id),
                INDEX idx_type       (invoice_type),
                INDEX idx_status     (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Table supplier_invoices created.\n";
    } else {
        echo "Table supplier_invoices already exists, skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
