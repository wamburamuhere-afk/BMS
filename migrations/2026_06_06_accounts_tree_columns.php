<?php
/**
 * 2026_06_06_accounts_tree_columns.php
 * ------------------------------------
 * Chart of Accounts — professional upgrade, Phase 1 (foundation).
 *
 * Adds three ADDITIVE, nullable/defaulted columns to `accounts` so the Chart
 * of Accounts page can render a MYOB-style indented tree, lock system-critical
 * accounts, and store a per-account natural side. Nothing existing is altered,
 * so every report / journal / payment path that reads `accounts` keeps working.
 *
 * New columns on `accounts`:
 *   - level          INT NULL                       → tree depth (1=top, 2=child, …)
 *   - is_system      TINYINT(1) NOT NULL DEFAULT 0  → 1 = wired to a system function;
 *                                                     edit/delete is locked in the UI + API
 *   - normal_balance ENUM('debit','credit') NULL    → per-account natural side
 *                                                     (defaults from the account's type)
 *
 * Backfill:
 *   - level: top accounts (no parent) = 1; children = parent.level + 1 (level-by-level).
 *   - normal_balance: copied from account_types.normal_side.
 *   - is_system = 1 for any account referenced by a `system_settings` *_account_id key
 *     (petty cash, AP, payroll, SDL, VAT, WHT, …) or by the `journal_mappings` table.
 *
 * Data hygiene: detaches any self-referencing parent (parent_account_id = own id)
 * to top-level — a corrupt state no professional COA (WorkDo/QuickBooks/MYOB) permits.
 *
 * Idempotent: SHOW COLUMNS / SHOW TABLES guards everywhere; safe to re-run.
 * No transaction wraps the DDL (MySQL auto-commits DDL).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: accounts tree columns (level, is_system, normal_balance)...\n";

try {
    // ── Guard: accounts table must exist ───────────────────────────────────
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ! accounts table missing — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // ── 1.B–1.D  Add the three columns (guarded) ───────────────────────────
    $columns = [
        'level'          => "INT NULL COMMENT 'Tree depth: 1=top, 2=child, 3=grandchild'",
        'is_system'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = system-critical account; edit/delete locked'",
        'normal_balance' => "ENUM('debit','credit') NULL COMMENT 'Per-account natural side; defaults from account type'",
    ];
    foreach ($columns as $col => $spec) {
        $exists = $pdo->query("SHOW COLUMNS FROM accounts LIKE " . $pdo->quote($col))->fetch();
        if ($exists) {
            echo "  · column accounts.$col already exists — skipped.\n";
        } else {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN `$col` $spec");
            echo "  + column accounts.$col added.\n";
        }
    }

    // ── 1.E  Data hygiene: detach self-referencing parents ─────────────────
    // An account that is its OWN parent is corrupt data — no professional chart
    // of accounts permits it (WorkDo, QuickBooks and MYOB all treat such a row
    // as top-level). Detaching the self-loop turns it into a clean top-level
    // account, exactly what those systems produce. Runs BEFORE the level calc
    // so depth is computed on clean data. Idempotent.
    $n = $pdo->exec("UPDATE accounts SET parent_account_id = NULL WHERE parent_account_id = account_id");
    if ($n > 0) echo "  + repaired $n self-referencing account(s) → detached to top-level.\n";
    else        echo "  · no self-referencing accounts to repair.\n";

    // ── 1.F  Reset, then recompute level from scratch every run so the
    //         backfill is BOTH correct and idempotent — re-running yields the
    //         same result and can never "run away" on a self-reference or a
    //         parent/child cycle. ───────────────────────────────────────────
    $pdo->exec("UPDATE accounts SET level = NULL");

    //   Roots become level 1: no parent, OR a self-reference (parent = self),
    //   OR a dangling parent (the referenced parent row does not exist).
    $n = $pdo->exec("
        UPDATE accounts a
          LEFT JOIN accounts p ON a.parent_account_id = p.account_id
           SET a.level = 1
         WHERE a.parent_account_id IS NULL
            OR a.parent_account_id = a.account_id
            OR p.account_id IS NULL
    ");
    echo "  + level=1 set on $n root account(s).\n";

    // ── 1.G  Fill children one depth at a time. We only ever assign a level
    //         to rows that do not have one yet (a.level IS NULL), so a cycle
    //         can never re-increment a row — the loop just converges and
    //         stops. Self-references are excluded from the join condition. ──
    $childStmt = $pdo->prepare("
        UPDATE accounts a
          JOIN accounts p ON a.parent_account_id = p.account_id
           SET a.level = p.level + 1
         WHERE a.level IS NULL
           AND p.level IS NOT NULL
           AND a.parent_account_id <> a.account_id
    ");
    $totalChild = 0;
    for ($i = 0; $i < 8; $i++) {        // 8 passes covers any realistic depth
        $childStmt->execute();
        $rc = $childStmt->rowCount();
        $totalChild += $rc;
        if ($rc === 0) break;           // converged — nothing left to fill
    }
    echo "  + child level backfilled on $totalChild account(s).\n";

    // ── Fallback: any row still NULL (a pure cycle with no root) → 1 ────────
    $n = $pdo->exec("UPDATE accounts SET level = 1 WHERE level IS NULL");
    if ($n > 0) echo "  + level fallback=1 applied to $n cyclic/edge account(s).\n";

    // ── 1.H  Backfill normal_balance from the account's type ───────────────
    $n = $pdo->exec("
        UPDATE accounts a
          JOIN account_types t ON a.account_type_id = t.type_id
           SET a.normal_balance = t.normal_side
         WHERE a.normal_balance IS NULL
           AND t.normal_side IS NOT NULL
    ");
    echo "  + normal_balance backfilled from account type on $n account(s).\n";

    // ── 1.I  Flag system accounts referenced in system_settings ────────────
    if ($pdo->query("SHOW TABLES LIKE 'system_settings'")->fetch()) {
        $n = $pdo->exec("
            UPDATE accounts SET is_system = 1
             WHERE account_id IN (
                SELECT CAST(setting_value AS UNSIGNED)
                  FROM system_settings
                 WHERE setting_key REGEXP '_account_id$'
                   AND setting_value REGEXP '^[0-9]+$'
                   AND CAST(setting_value AS UNSIGNED) > 0
             )
        ");
        echo "  + is_system=1 flagged on $n account(s) referenced by system_settings.\n";
    } else {
        echo "  · system_settings table absent — skipped settings flagging.\n";
    }

    // ── 1.J  Flag system accounts referenced in journal_mappings ───────────
    if ($pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch()) {
        $n = $pdo->exec("
            UPDATE accounts SET is_system = 1
             WHERE account_id IN (
                SELECT debit_account_id  FROM journal_mappings WHERE debit_account_id  IS NOT NULL
                UNION
                SELECT credit_account_id FROM journal_mappings WHERE credit_account_id IS NOT NULL
             )
        ");
        echo "  + is_system=1 flagged on $n account(s) referenced by journal_mappings.\n";
    } else {
        echo "  · journal_mappings table absent — skipped mapping flagging.\n";
    }

    // ── 1.K  Summary ───────────────────────────────────────────────────────
    $sys   = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_system = 1")->fetchColumn();
    $lvls  = $pdo->query("SELECT COALESCE(MAX(level),0) FROM accounts")->fetchColumn();
    $nbset = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE normal_balance IS NOT NULL")->fetchColumn();
    echo "\n  Summary: $sys system account(s); deepest level = $lvls; normal_balance set on $nbset account(s).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
