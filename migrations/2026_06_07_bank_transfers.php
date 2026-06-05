<?php
/**
 * 2026_06_07_bank_transfers.php
 * -----------------------------
 * Plan 2 — Bank / Cash Transfer (post-gated, with charges).
 *
 *  1. CREATE bank_transfers — a from→to money move through the standard
 *     pending → reviewed → approved → posted workflow (e-signature captured),
 *     with optional transfer charges. No money moves until it is POSTED.
 *  2. Add 'transfer' to transactions.transaction_type so the posted ledger entry
 *     classifies cleanly (additive enum change; existing values untouched).
 *  3. Seed the `bank_transfers` permission + role grants (mirrors the access of
 *     the existing `bank_accounts` page).
 *
 * Idempotent + additive — safe to re-run. No DDL inside a transaction (MySQL DDL
 * auto-commits).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: bank_transfers (table + enum + permission)...\n";

try {
    // ── 1. bank_transfers table ───────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_transfers (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            transfer_number   VARCHAR(50)  NOT NULL,
            transfer_date     DATE         NOT NULL,
            from_account_id   INT          NOT NULL,
            to_account_id     INT          NOT NULL,
            amount            DECIMAL(15,2) NOT NULL,
            charges           DECIMAL(15,2) NOT NULL DEFAULT 0,
            charge_account_id INT          NULL,
            reference_number  VARCHAR(100) NULL,
            description       TEXT         NULL,
            project_id        INT          NULL,
            status            ENUM('pending','reviewed','approved','posted','rejected') NOT NULL DEFAULT 'pending',
            transaction_id    INT          NULL,
            created_by        INT          NULL,
            reviewed_by       INT          NULL,
            reviewed_by_name  VARCHAR(150) NULL,
            reviewed_by_role  VARCHAR(100) NULL,
            reviewed_at       DATETIME     NULL,
            approved_by       INT          NULL,
            approved_by_name  VARCHAR(150) NULL,
            approved_by_role  VARCHAR(100) NULL,
            approved_at       DATETIME     NULL,
            posted_by         INT          NULL,
            posted_at         DATETIME     NULL,
            updated_by        INT          NULL,
            created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bt_status (status),
            KEY idx_bt_from (from_account_id),
            KEY idx_bt_to (to_account_id),
            KEY idx_bt_date (transfer_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + bank_transfers table ready.\n";

    // ── 2. Add 'transfer' to transactions.transaction_type enum ───────────
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'transfer'") === false) {
        // Re-declare the full enum with the new value appended.
        $pdo->exec("
            ALTER TABLE transactions MODIFY transaction_type
            ENUM('disbursement','repayment','fee','interest','expense','general',
                 'supplier_payment','received_invoice_payment','sc_payment','payroll',
                 'voucher','petty_cash','transfer') NOT NULL
        ");
        echo "  + 'transfer' added to transactions.transaction_type.\n";
    } else {
        echo "  = transaction_type already includes 'transfer' — skipped.\n";
    }

    // ── 3. Permission + role grants (mirror bank_accounts access) ─────────
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['bank_transfers']);
    if (!$existing->fetch()) {
        $pdo->prepare("INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name)
                       VALUES (?, ?, ?, ?)")
            ->execute(['bank_transfers', 'Bank Transfers', 'Bank Transfers', 'Finance']);
        echo "  + bank_transfers permission inserted.\n";
    } else {
        echo "  = bank_transfers permission already exists.\n";
    }

    $pid = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'bank_transfers'")->fetchColumn();
    $bankPid = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'bank_accounts'")->fetchColumn();

    if ($pid && $bankPid) {
        // Copy the exact role grants from bank_accounts so the same roles that
        // manage bank accounts can manage transfers (admins bypass anyway).
        $cols = ['can_view', 'can_create', 'can_edit', 'can_delete'];
        foreach (['can_review', 'can_approve'] as $opt) {
            if ($pdo->query("SHOW COLUMNS FROM role_permissions LIKE '$opt'")->fetch()) $cols[] = $opt;
        }
        $colList = implode(', ', $cols);
        $src = $pdo->prepare("SELECT role_id, $colList FROM role_permissions WHERE permission_id = ?");
        $src->execute([$bankPid]);
        $rows = $src->fetchAll(PDO::FETCH_ASSOC);
        $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, $colList)
                              VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ")");
        foreach ($rows as $r) {
            $vals = [$r['role_id'], $pid];
            foreach ($cols as $c) $vals[] = (int)$r[$c];
            $ins->execute($vals);
        }
        echo "  + copied " . count($rows) . " role grant(s) from bank_accounts.\n";

        // Guarantee Admin (role 1) full access even if bank_accounts had none.
        $adminCols = 'role_id, permission_id, can_view, can_create, can_edit, can_delete'
            . (in_array('can_review', $cols, true) ? ', can_review' : '')
            . (in_array('can_approve', $cols, true) ? ', can_approve' : '');
        $adminVals = [1, $pid, 1, 1, 1, 1];
        if (in_array('can_review', $cols, true))  $adminVals[] = 1;
        if (in_array('can_approve', $cols, true)) $adminVals[] = 1;
        $marks = implode(', ', array_fill(0, count($adminVals), '?'));
        $pdo->prepare("INSERT IGNORE INTO role_permissions ($adminCols) VALUES ($marks)")->execute($adminVals);
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
