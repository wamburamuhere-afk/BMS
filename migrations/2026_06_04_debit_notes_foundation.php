<?php
/**
 * 2026_06_04_debit_notes_foundation.php
 * -------------------------------------
 * Foundation for the standalone DEBIT NOTE document (issued to a supplier).
 * A debit note follows the canonical three-approval workflow
 * (pending → reviewed → approved) and, once approved, is settled by a cash
 * REFUND IN received from the supplier (postInflow): Dr Cash/Bank /
 * Cr "Supplier Credit Notes" (Other Income).
 *
 * What it does (idempotent; no transactions around DDL):
 *   1. debit_notes            — header (supplier, origin links, totals, status,
 *                               three-approval cols, payment cols).
 *   2. debit_note_items       — line items.
 *   3. permission page_key='debit_notes' + full grants to roles 1 (Admin) and
 *      2 (Managing Director). Default-deny for everyone else.
 *   4. GL account "Supplier Credit Notes" (Other Income) +
 *      system_settings.default_supplier_credits_account_id. cash_flow_category
 *      'operating' keeps it OUT of the Received-Into cash/bank picker.
 *
 * Purely ADDITIVE. The income statement Other Income line already reads paid
 * debit_notes; cash flow already shows a "Supplier refunds (debit notes)" line.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Debit Notes foundation...\n";

try {
    // ── 1. debit_notes header table ─────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS debit_notes (
            debit_note_id         INT AUTO_INCREMENT PRIMARY KEY,
            debit_note_number     VARCHAR(50)  NOT NULL,
            supplier_id           INT          NOT NULL,
            purchase_return_id    INT          NULL,
            purchase_order_id     INT          NULL,
            debit_date            DATE         NOT NULL,
            reason                VARCHAR(255) NULL,
            notes                 TEXT         NULL,
            subtotal_amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_tax             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            grand_total           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            status                ENUM('pending','reviewed','approved','paid','rejected','cancelled','deleted')
                                  NOT NULL DEFAULT 'pending',
            reviewed_by           INT          NULL,
            reviewed_at           DATETIME     NULL,
            approved_by           INT          NULL,
            approved_at           DATETIME     NULL,
            paid_by               INT          NULL,
            paid_at               DATETIME     NULL,
            received_into_account_id INT       NULL,
            payment_transaction_id   INT       NULL,
            payment_reference     VARCHAR(100) NULL,
            created_by            INT          NULL,
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dn_supplier (supplier_id),
            KEY idx_dn_return   (purchase_return_id),
            KEY idx_dn_status   (status),
            KEY idx_dn_date     (debit_date),
            KEY idx_dn_number   (debit_note_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + debit_notes table ready.\n";

    // ── 2. debit_note_items line table ──────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS debit_note_items (
            item_id         INT AUTO_INCREMENT PRIMARY KEY,
            debit_note_id   INT          NOT NULL,
            product_id      INT          NULL,
            description     VARCHAR(255) NULL,
            quantity        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
            tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            KEY idx_dni_note (debit_note_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + debit_note_items table ready.\n";

    // ── 3. Permission row + role grants ─────────────────────────────────────
    $hasPNCol = (bool)$pdo->query("SHOW COLUMNS FROM permissions LIKE 'permission_name'")->fetch();
    $permSql = $hasPNCol
        ? "INSERT INTO permissions (page_key, page_name, permission_name, description, module_name, created_at)
           SELECT 'debit_notes','Debit Notes','Debit Notes','Issue & settle supplier debit notes','Procurement', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'debit_notes')"
        : "INSERT INTO permissions (page_key, page_name, description, module_name, created_at)
           SELECT 'debit_notes','Debit Notes','Issue & settle supplier debit notes','Procurement', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'debit_notes')";
    $pdo->exec($permSql);
    $permId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'debit_notes' LIMIT 1")->fetchColumn();
    echo "  + permission 'debit_notes' ensured (permission_id = {$permId}).\n";

    if ($permId) {
        $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
        $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();
        $cols  = "role_id, permission_id, can_view, can_create, can_edit, can_delete";
        $vals  = "?, ?, 1, 1, 1, 1";
        if ($hasReview)  { $cols .= ", can_review";  $vals .= ", 1"; }
        if ($hasApprove) { $cols .= ", can_approve"; $vals .= ", 1"; }
        $grant = $pdo->prepare("
            INSERT INTO role_permissions ($cols)
            SELECT $vals
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions rp WHERE rp.role_id = ? AND rp.permission_id = ?
            )
        ");
        foreach ([1, 2] as $roleId) {
            $grant->execute([$roleId, $permId, $roleId, $permId]);
            echo $grant->rowCount() > 0
                ? "    + granted full debit_notes access to role {$roleId}.\n"
                : "    · role {$roleId} already has a debit_notes grant.\n";
        }
    }

    // ── 4. "Supplier Credit Notes" Other-Income account + setting ───────────
    // BEST-EFFORT: the tables + permissions above are the essential deliverable.
    // This income account is only needed at PAYMENT time, and pay_debit_note.php
    // already degrades gracefully when the setting is absent. Production schema
    // variance (no account_types.category column, no 'revenue' classification,
    // strict-mode NOT NULL columns, a missing accounts table, …) must therefore
    // NEVER abort the migration — and with it the whole deploy. So the entire
    // block runs inside its own non-fatal try/catch.
    try {
        $revTypeId = null;
        try {
            if ($pdo->query("SHOW COLUMNS FROM account_types LIKE 'category'")->fetch()) {
                $v = $pdo->query("SELECT type_id FROM account_types WHERE category = 'revenue' LIMIT 1")->fetchColumn();
                if ($v !== false) $revTypeId = (int)$v;
            }
            if ($revTypeId === null) {
                $v = $pdo->query("SELECT type_id FROM account_types WHERE type_name LIKE '%revenue%' OR type_name LIKE '%income%' LIMIT 1")->fetchColumn();
                if ($v !== false) $revTypeId = (int)$v;
            }
        } catch (Throwable $e) { $revTypeId = null; }

        $scId = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Supplier Credit Notes' LIMIT 1")->fetchColumn();
        if ($scId) {
            $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
                ->execute([$revTypeId, (int)$scId]);
            $scId = (int)$scId;
            echo "  · Supplier Credit Notes account already exists, id = {$scId}.\n";
        } else {
            $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                              cash_flow_category, opening_balance, current_balance, status, created_at)
                           VALUES ('SUP-CREDIT', 'Supplier Credit Notes', 'revenue', ?, 'operating', 0, 0, 'active', NOW())")
                ->execute([$revTypeId]);
            $scId = (int)$pdo->lastInsertId();
            echo "  + Supplier Credit Notes account created, id = {$scId} (type_id " . ($revTypeId ?? 'NULL') . ").\n";
        }

        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                       VALUES ('default_supplier_credits_account_id', ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([(string)$scId]);
        echo "  + setting default_supplier_credits_account_id = {$scId}.\n";
    } catch (Throwable $e) {
        echo "  ! GL account/setting seeding skipped (non-fatal): " . $e->getMessage() . "\n";
        echo "    debit_notes tables + permissions are installed; configure the\n";
        echo "    'Supplier Credit Notes' account / default_supplier_credits_account_id later.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
