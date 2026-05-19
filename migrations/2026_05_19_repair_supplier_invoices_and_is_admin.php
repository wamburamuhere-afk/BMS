<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting repair migration: supplier_invoices table + roles.is_admin column...\n";

try {
    // 1. Create supplier_invoices if missing
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
                INDEX idx_supplier (supplier_id),
                INDEX idx_po       (po_id),
                INDEX idx_project  (project_id),
                INDEX idx_type     (invoice_type),
                INDEX idx_status   (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "Table supplier_invoices created.\n";
    } else {
        echo "Table supplier_invoices already exists, skipping.\n";
    }

    // 2. Add is_admin column to roles if missing
    $col = $pdo->query("SHOW COLUMNS FROM roles LIKE 'is_admin'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role_name");
        $pdo->exec("UPDATE roles SET is_admin = 1 WHERE role_id = 1");
        echo "Column is_admin added to roles and role_id=1 marked as admin.\n";
    } else {
        echo "Column is_admin already exists, skipping.\n";
    }

    // 3. Ensure received_invoices permission exists
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'received_invoices'")->fetch();
    if (!$perm) {
        $pdo->exec("
            INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name, description)
            VALUES ('received_invoices', 'Received Invoices', 'Received Invoices', 'Finance', 'Record and manage invoices received from suppliers and sub-contractors')
        ");
        echo "Permission received_invoices inserted.\n";
    } else {
        echo "Permission received_invoices already exists, skipping.\n";
    }

    // 4. Assign role_permissions for received_invoices to all roles
    $permId = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'received_invoices'")->fetchColumn();
    if ($permId) {
        $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
        $fullAccess = [1, 2, 5, 6, 7];
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO role_permissions
                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($roles as $rid) {
            $full = in_array((int)$rid, $fullAccess) ? 1 : 0;
            $stmt->execute([$rid, $permId, 1, $full, $full, $full, $full, $full]);
        }
        echo "role_permissions assigned for received_invoices.\n";
    }

    echo "Repair migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
