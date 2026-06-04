<?php
/**
 * 2026_06_04_credit_notes_foundation.php
 * --------------------------------------
 * Foundation for the standalone CREDIT NOTE document (issued to a customer).
 * A credit note follows the canonical three-approval workflow
 * (pending → reviewed → approved) and, once approved, is settled by a cash
 * REFUND OUT to the customer (postOutflow): Dr "Sales Returns & Allowances"
 * (contra-revenue) / Cr Cash/Bank.
 *
 * What it does (all idempotent; no transactions around DDL):
 *   1. credit_notes            — header (customer, origin links, totals, status,
 *                                three-approval cols, payment cols).
 *   2. credit_note_items       — line items.
 *   3. permission page_key='credit_notes' + full grants to roles 1 (Admin) and
 *      2 (Managing Director). Default-deny for everyone else (admin bypasses).
 *   4. GL account "Sales Returns & Allowances" (contra-revenue) +
 *      system_settings.default_sales_returns_account_id. cash_flow_category
 *      'operating' (NOT 'cash') keeps it OUT of the Paid-From picker.
 *
 * Purely ADDITIVE — nothing existing is dropped or renamed. The income
 * statement / cash flow already degrade to 0 when these tables are absent,
 * so this migration simply lights up the new source.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Credit Notes foundation...\n";

try {
    // ── 1. credit_notes header table ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credit_notes (
            credit_note_id        INT AUTO_INCREMENT PRIMARY KEY,
            credit_note_number    VARCHAR(50)  NOT NULL,
            customer_id           INT          NOT NULL,
            sales_return_id       INT          NULL,
            invoice_id            INT          NULL,
            credit_date           DATE         NOT NULL,
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
            paid_from_account_id  INT          NULL,
            payment_transaction_id INT         NULL,
            payment_reference     VARCHAR(100) NULL,
            created_by            INT          NULL,
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cn_customer  (customer_id),
            KEY idx_cn_return    (sales_return_id),
            KEY idx_cn_status    (status),
            KEY idx_cn_date      (credit_date),
            KEY idx_cn_number    (credit_note_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + credit_notes table ready.\n";

    // ── 2. credit_note_items line table ─────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credit_note_items (
            item_id         INT AUTO_INCREMENT PRIMARY KEY,
            credit_note_id  INT          NOT NULL,
            product_id      INT          NULL,
            description     VARCHAR(255) NULL,
            quantity        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            unit_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            tax_rate        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
            tax_amount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            total_amount    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            KEY idx_cni_note (credit_note_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + credit_note_items table ready.\n";

    // ── 3. Permission row + role grants ─────────────────────────────────────
    $hasPNCol = (bool)$pdo->query("SHOW COLUMNS FROM permissions LIKE 'permission_name'")->fetch();
    $permSql = $hasPNCol
        ? "INSERT INTO permissions (page_key, page_name, permission_name, description, module_name, created_at)
           SELECT 'credit_notes','Credit Notes','Credit Notes','Issue & settle customer credit notes','Sales', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'credit_notes')"
        : "INSERT INTO permissions (page_key, page_name, description, module_name, created_at)
           SELECT 'credit_notes','Credit Notes','Issue & settle customer credit notes','Sales', NOW()
           WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.page_key = 'credit_notes')";
    $pdo->exec($permSql);
    $permId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'credit_notes' LIMIT 1")->fetchColumn();
    echo "  + permission 'credit_notes' ensured (permission_id = {$permId}).\n";

    if ($permId) {
        // Detect optional workflow columns (present on current servers).
        $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
        $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();

        $cols  = "role_id, permission_id, can_view, can_create, can_edit, can_delete";
        $vals  = "?, ?, 1, 1, 1, 1";
        if ($hasReview)  { $cols .= ", can_review";  $vals .= ", 1"; }
        if ($hasApprove) { $cols .= ", can_approve"; $vals .= ", 1"; }

        // NOT EXISTS guard makes this safe regardless of any unique key.
        $grant = $pdo->prepare("
            INSERT INTO role_permissions ($cols)
            SELECT $vals
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                 WHERE rp.role_id = ? AND rp.permission_id = ?
            )
        ");
        foreach ([1, 2] as $roleId) {
            $grant->execute([$roleId, $permId, $roleId, $permId]);
            if ($grant->rowCount() > 0) echo "    + granted full credit_notes access to role {$roleId}.\n";
            else                        echo "    · role {$roleId} already has a credit_notes grant.\n";
        }
    }

    // ── 4. "Sales Returns & Allowances" contra-revenue account + setting ─────
    // BEST-EFFORT: the tables + permissions above are the essential deliverable.
    // This contra account is only needed at PAYMENT time, and pay_credit_note.php
    // already degrades gracefully when the setting is absent. Production schema
    // variance (no account_types.category column, no 'revenue' classification,
    // strict-mode NOT NULL columns, a missing accounts table, …) must therefore
    // NEVER abort the migration — and with it the whole deploy. So the entire
    // block runs inside its own non-fatal try/catch.
    try {
        // account_types.category may be absent on some servers — guard it, then
        // fall back to a name-based lookup, else leave NULL.
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

        $sraId = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Sales Returns & Allowances' LIMIT 1")->fetchColumn();
        if ($sraId) {
            $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
                ->execute([$revTypeId, (int)$sraId]);
            $sraId = (int)$sraId;
            echo "  · Sales Returns & Allowances account already exists, id = {$sraId}.\n";
        } else {
            $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                              cash_flow_category, opening_balance, current_balance, status, created_at)
                           VALUES ('SRA-CONTRA', 'Sales Returns & Allowances', 'revenue', ?, 'operating', 0, 0, 'active', NOW())")
                ->execute([$revTypeId]);
            $sraId = (int)$pdo->lastInsertId();
            echo "  + Sales Returns & Allowances account created, id = {$sraId} (type_id " . ($revTypeId ?? 'NULL') . ").\n";
        }

        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                       VALUES ('default_sales_returns_account_id', ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
            ->execute([(string)$sraId]);
        echo "  + setting default_sales_returns_account_id = {$sraId}.\n";
    } catch (Throwable $e) {
        echo "  ! GL account/setting seeding skipped (non-fatal): " . $e->getMessage() . "\n";
        echo "    credit_notes tables + permissions are installed; configure the\n";
        echo "    'Sales Returns & Allowances' account / default_sales_returns_account_id later.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
