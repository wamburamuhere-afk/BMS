<?php
/**
 * 2026_06_08_revenue.php
 * ----------------------
 * Plan 3 — Standalone Revenue / "Other Income" (the income-side twin of expenses).
 *
 *  1. CREATE revenue_categories — a simple category → sub-category tree for
 *     grouping non-sales income (interest, grants, asset disposal, misc).
 *  2. CREATE revenues — a post-gated income record through the standard
 *     pending → reviewed → approved → posted workflow (e-signature captured).
 *     Money is received (cash in + Cr income) only at the Posted step.
 *  3. Add 'revenue' to transactions.transaction_type.
 *  4. Seed `revenue` + `revenue_categories` permissions (mirror `expenses`).
 *  5. Seed a few default categories.
 *
 * Idempotent + additive — safe to re-run. No DDL inside a transaction.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: revenue (categories + revenues + enum + permissions)...\n";

try {
    // ── 1. revenue_categories ─────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS revenue_categories (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100) NOT NULL,
            parent_id  INT NULL,
            status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rc_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + revenue_categories table ready.\n";

    // ── 2. revenues ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS revenues (
            revenue_id        INT AUTO_INCREMENT PRIMARY KEY,
            revenue_number    VARCHAR(50)  NOT NULL,
            revenue_date      DATE         NOT NULL,
            category_id       INT          NULL,
            income_account_id INT          NOT NULL,
            bank_account_id   INT          NOT NULL,
            amount            DECIMAL(15,2) NOT NULL,
            payer_name        VARCHAR(255) NULL,
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
            KEY idx_rev_status (status),
            KEY idx_rev_date (revenue_date),
            KEY idx_rev_cat (category_id),
            KEY idx_rev_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + revenues table ready.\n";

    // ── 3. Add 'revenue' to transactions.transaction_type enum ────────────
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'revenue'") === false) {
        $pdo->exec("
            ALTER TABLE transactions MODIFY transaction_type
            ENUM('disbursement','repayment','fee','interest','expense','general',
                 'supplier_payment','received_invoice_payment','sc_payment','payroll',
                 'voucher','petty_cash','transfer','revenue') NOT NULL
        ");
        echo "  + 'revenue' added to transactions.transaction_type.\n";
    } else {
        echo "  = transaction_type already includes 'revenue' — skipped.\n";
    }

    // ── 4. Permissions (mirror `expenses` access) ─────────────────────────
    $seedPerm = function (string $key, string $name) use ($pdo) {
        $ex = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
        $ex->execute([$key]);
        if (!$ex->fetch()) {
            $pdo->prepare("INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name)
                           VALUES (?, ?, ?, 'Finance')")->execute([$key, $name, $name]);
            echo "  + $key permission inserted.\n";
        } else {
            echo "  = $key permission already exists.\n";
        }
        $pid    = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = " . $pdo->quote($key))->fetchColumn();
        $srcPid = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'expenses'")->fetchColumn();
        if (!$pid || !$srcPid) return;

        $cols = ['can_view', 'can_create', 'can_edit', 'can_delete'];
        foreach (['can_review', 'can_approve'] as $opt) {
            if ($pdo->query("SHOW COLUMNS FROM role_permissions LIKE '$opt'")->fetch()) $cols[] = $opt;
        }
        $colList = implode(', ', $cols);
        $src = $pdo->prepare("SELECT role_id, $colList FROM role_permissions WHERE permission_id = ?");
        $src->execute([$srcPid]);
        $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, $colList)
                              VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ")");
        foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vals = [$r['role_id'], $pid];
            foreach ($cols as $c) $vals[] = (int)$r[$c];
            $ins->execute($vals);
        }
        // Guarantee Admin (role 1) full access.
        $adminCols = 'role_id, permission_id, ' . $colList;
        $adminVals = [1, $pid];
        foreach ($cols as $c) $adminVals[] = 1;
        $marks = implode(', ', array_fill(0, count($adminVals), '?'));
        $pdo->prepare("INSERT IGNORE INTO role_permissions ($adminCols) VALUES ($marks)")->execute($adminVals);
    };
    $seedPerm('revenue', 'Revenue / Other Income');
    $seedPerm('revenue_categories', 'Revenue Categories');

    // ── 5. Default categories ─────────────────────────────────────────────
    foreach (['Interest Income', 'Grants & Donations', 'Asset Disposal', 'Rental Income', 'Miscellaneous Income'] as $c) {
        $exists = $pdo->prepare("SELECT 1 FROM revenue_categories WHERE name = ? AND parent_id IS NULL");
        $exists->execute([$c]);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO revenue_categories (name, parent_id, status) VALUES (?, NULL, 'active')")->execute([$c]);
        }
    }
    echo "  + default revenue categories seeded.\n";

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
