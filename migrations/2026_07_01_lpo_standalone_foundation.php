<?php
/**
 * 2026_07_01_lpo_standalone_foundation.php
 * -----------------------------------------
 * Foundation for the standalone CUSTOMER LPO module (mirrors Purchase Order).
 * Today, customer_lpos only exists as a tab embedded in a customer's detail
 * page, with no reviewed_by/approved_by columns and no dedicated permission —
 * everything piggy-backs on the generic 'customers' page_key. This migration:
 *
 *   1. Adds three-approval workflow snapshot columns to customer_lpos
 *      (prepared/reviewed/approved by name+role+at, matching purchase_orders).
 *   2. Adds project_id to customer_lpos (for project-scope filtering on the
 *      new standalone list page).
 *   3. Adds product_id to customer_lpo_items (PO-parity live product links;
 *      existing free-text rows stay valid with product_id = NULL).
 *   4. Adds customer_lpo_id to deliveries (link an outbound DN back to the
 *      LPO it fulfills).
 *   5. Adds delivery_id + customer_lpo_id to invoices (optional traceability
 *      refs, per explicit product decision).
 *   6. Registers permission page_key='lpo', granting view/create/edit/delete
 *      to every role that currently has can_create=1 OR can_edit=1 on
 *      'customers' (no regression for existing LPO users), and
 *      review/approve only to role 1 (Admin) and role 2 (Managing Director).
 *
 * Purely ADDITIVE — no data is deleted or altered. 'LPO' is already a
 * registered code_sequences entity (2026_06_27_company_code_sequences.php),
 * so no numbering migration is needed here.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: LPO standalone foundation...\n";

try {
    // ── 1+2. customer_lpos — workflow snapshot + project_id ─────────────────
    $lpoCols = [
        'project_id'       => "ALTER TABLE customer_lpos ADD COLUMN project_id INT NULL AFTER customer_id",
        'prepared_by_name' => "ALTER TABLE customer_lpos ADD COLUMN prepared_by_name VARCHAR(150) NULL",
        'prepared_by_role' => "ALTER TABLE customer_lpos ADD COLUMN prepared_by_role VARCHAR(100) NULL",
        'prepared_at'      => "ALTER TABLE customer_lpos ADD COLUMN prepared_at DATETIME NULL",
        'reviewed_by'      => "ALTER TABLE customer_lpos ADD COLUMN reviewed_by INT NULL",
        'reviewed_by_name' => "ALTER TABLE customer_lpos ADD COLUMN reviewed_by_name VARCHAR(150) NULL",
        'reviewed_by_role' => "ALTER TABLE customer_lpos ADD COLUMN reviewed_by_role VARCHAR(100) NULL",
        'reviewed_at'      => "ALTER TABLE customer_lpos ADD COLUMN reviewed_at DATETIME NULL",
        'approved_by'      => "ALTER TABLE customer_lpos ADD COLUMN approved_by INT NULL",
        'approved_by_name' => "ALTER TABLE customer_lpos ADD COLUMN approved_by_name VARCHAR(150) NULL",
        'approved_by_role' => "ALTER TABLE customer_lpos ADD COLUMN approved_by_role VARCHAR(100) NULL",
        'approved_at'      => "ALTER TABLE customer_lpos ADD COLUMN approved_at DATETIME NULL",
    ];
    foreach ($lpoCols as $col => $sql) {
        $exists = (bool)$pdo->query("SHOW COLUMNS FROM customer_lpos LIKE '$col'")->fetch();
        if ($exists) {
            echo "  · customer_lpos.$col already exists — skipping.\n";
        } else {
            $pdo->exec($sql);
            echo "  + customer_lpos.$col added.\n";
        }
    }
    $hasIdx = (bool)$pdo->query("SHOW INDEX FROM customer_lpos WHERE Key_name = 'idx_lpo_project_id'")->fetch();
    if (!$hasIdx) {
        $pdo->exec("ALTER TABLE customer_lpos ADD INDEX idx_lpo_project_id (project_id)");
        echo "  + index idx_lpo_project_id added.\n";
    }

    // ── 3. customer_lpo_items — product_id (PO parity) ──────────────────────
    $hasProductId = (bool)$pdo->query("SHOW COLUMNS FROM customer_lpo_items LIKE 'product_id'")->fetch();
    if ($hasProductId) {
        echo "  · customer_lpo_items.product_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE customer_lpo_items ADD COLUMN product_id INT NULL AFTER lpo_id");
        $pdo->exec("ALTER TABLE customer_lpo_items ADD INDEX idx_lpo_items_product_id (product_id)");
        echo "  + customer_lpo_items.product_id added.\n";
    }

    // ── 4. deliveries — customer_lpo_id (LPO -> DN(outbound) link) ──────────
    $hasDelLpo = (bool)$pdo->query("SHOW COLUMNS FROM deliveries LIKE 'customer_lpo_id'")->fetch();
    if ($hasDelLpo) {
        echo "  · deliveries.customer_lpo_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN customer_lpo_id INT NULL AFTER purchase_order_id");
        $pdo->exec("ALTER TABLE deliveries ADD INDEX idx_del_customer_lpo_id (customer_lpo_id)");
        echo "  + deliveries.customer_lpo_id added.\n";
    }

    // ── 5. invoices — delivery_id + customer_lpo_id (optional refs) ─────────
    $hasInvDelivery = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'delivery_id'")->fetch();
    if ($hasInvDelivery) {
        echo "  · invoices.delivery_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN delivery_id INT NULL AFTER order_id");
        $pdo->exec("ALTER TABLE invoices ADD INDEX idx_inv_delivery_id (delivery_id)");
        echo "  + invoices.delivery_id added.\n";
    }
    $hasInvLpo = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'customer_lpo_id'")->fetch();
    if ($hasInvLpo) {
        echo "  · invoices.customer_lpo_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN customer_lpo_id INT NULL AFTER delivery_id");
        $pdo->exec("ALTER TABLE invoices ADD INDEX idx_inv_customer_lpo_id (customer_lpo_id)");
        echo "  + invoices.customer_lpo_id added.\n";
    }

    // ── 6. Permission page_key='lpo' + role grants ──────────────────────────
    $hasPNCol = (bool)$pdo->query("SHOW COLUMNS FROM permissions LIKE 'permission_name'")->fetch();
    $permSql = $hasPNCol
        ? "INSERT INTO permissions (page_key, page_name, permission_name, description, module_name, created_at)
           SELECT 'lpo','Customer LPO','Customer LPO','Standalone customer LPO module (create/review/approve, DN & invoice linkage)','Sales', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'lpo')"
        : "INSERT INTO permissions (page_key, page_name, description, module_name, created_at)
           SELECT 'lpo','Customer LPO','Standalone customer LPO module (create/review/approve, DN & invoice linkage)','Sales', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'lpo')";
    $pdo->exec($permSql);
    $permId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'lpo' LIMIT 1")->fetchColumn();
    echo "  + permission 'lpo' ensured (permission_id = {$permId}).\n";

    if ($permId) {
        $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
        $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();

        // Grant view/create/edit/delete to every role that currently has
        // can_create=1 OR can_edit=1 on 'customers' — no regression for
        // whoever can add LPOs today via the customer tab.
        $customersPermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'customers' LIMIT 1")->fetchColumn();
        $roleIds = [1, 2]; // Admin, Managing Director always included
        if ($customersPermId) {
            $rp = $pdo->prepare("SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = ? AND (can_create = 1 OR can_edit = 1)");
            $rp->execute([$customersPermId]);
            foreach ($rp->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $roleIds[] = (int)$rid;
            }
        }
        $roleIds = array_values(array_unique($roleIds));

        $grant = $pdo->prepare("
            INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete)
            SELECT ?, ?, 1, 1, 1, 1
            WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = ? AND rp.permission_id = ?)
        ");
        foreach ($roleIds as $roleId) {
            $grant->execute([$roleId, $permId, $roleId, $permId]);
            echo $grant->rowCount() > 0
                ? "    + granted view/create/edit/delete 'lpo' to role {$roleId}.\n"
                : "    · role {$roleId} already has an 'lpo' grant.\n";
        }

        // Review/Approve — separation of duties: Admin + Managing Director only.
        if ($hasReview && $hasApprove) {
            $upd = $pdo->prepare("UPDATE role_permissions SET can_review = 1, can_approve = 1 WHERE role_id = ? AND permission_id = ?");
            foreach ([1, 2] as $roleId) {
                $upd->execute([$roleId, $permId]);
                echo "    + can_review/can_approve set for role {$roleId}.\n";
            }
        }
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
