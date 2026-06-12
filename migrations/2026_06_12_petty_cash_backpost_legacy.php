<?php
/**
 * 2026_06_12_petty_cash_backpost_legacy.php
 * -----------------------------------------
 * Posts the historical petty cash transactions that were recorded before the
 * ledger wiring existed (transaction_id IS NULL) so the petty cash balance is
 * complete. Uses SAFE DEFAULTS and FLAGS each one (needs_review = 1) so the user
 * can re-categorise it via the normal edit flow (which re-posts to the right
 * accounts).
 *
 *   default fund     = configured default petty cash account
 *   deposit funding  = Opening Balance Equity (standard for historical cash)
 *   expense account  = a dedicated "Petty Cash – Uncategorised" holding account
 *                      (created here if missing) so real expense lines aren't
 *                      polluted while the entry awaits review.
 *
 * Idempotent: only processes rows still missing a ledger entry; re-running is a
 * no-op. Criteria-based — no hard-coded ids.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

echo "Starting migration: back-post legacy petty cash transactions...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'petty_cash_transactions'")->fetch()) {
        echo "  ~ petty_cash_transactions absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    // 1. needs_review flag (idempotent)
    if (!$pdo->query("SHOW COLUMNS FROM petty_cash_transactions LIKE 'needs_review'")->fetch()) {
        $pdo->exec("ALTER TABLE petty_cash_transactions ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER source_account_id");
        echo "  + needs_review column added.\n";
    } else {
        echo "  ~ needs_review already exists.\n";
    }

    // 2. Resolve safe-default accounts
    $fund = (int)(pettyCashAccountId($pdo) ?: 0);
    if (!$fund) { echo "  ! No default petty cash fund configured — cannot back-post.\n\nMigration complete.\n"; exit(0); }

    $obe = (int)($pdo->query("SELECT account_id FROM accounts
        WHERE (account_name LIKE '%opening balance%' OR account_name LIKE '%opening%equity%') AND status='active' LIMIT 1")->fetchColumn() ?: 0);
    if (!$obe) {
        // fall back to any active equity leaf
        $obe = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
            WHERE at.category='equity' AND a.status='active' LIMIT 1")->fetchColumn() ?: 0);
    }

    // dedicated holding expense account "Petty Cash – Uncategorised" (create if missing)
    $uncat = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='PC-UNCAT' OR account_name='Petty Cash – Uncategorised' LIMIT 1")->fetchColumn() ?: 0);
    if (!$uncat) {
        $expType = (int)($pdo->query("SELECT type_id FROM account_types WHERE category='expense' LIMIT 1")->fetchColumn() ?: 0);
        if ($expType) {
            // parent under the top Expenses account if there is one
            $parent = $pdo->query("SELECT account_id, level FROM accounts WHERE account_type='expense' AND parent_account_id IS NULL ORDER BY account_code LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $pid = $parent ? (int)$parent['account_id'] : null;
            $lvl = $parent ? (int)$parent['level'] + 1 : 1;
            $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type_id, account_type, cash_flow_category, parent_account_id, level, normal_balance, status, created_at, updated_at)
                           VALUES ('PC-UNCAT', 'Petty Cash – Uncategorised', ?, 'expense', 'operating', ?, ?, 'debit', 'active', NOW(), NOW())")
                ->execute([$expType, $pid, $lvl]);
            $uncat = (int)$pdo->lastInsertId();
            echo "  + Created holding account 'Petty Cash – Uncategorised' (PC-UNCAT).\n";
        }
    }
    if (!$uncat) {
        // last resort: first expense account
        $uncat = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
            WHERE at.category IN ('expense','finance_cost') AND a.status='active' LIMIT 1")->fetchColumn() ?: 0);
    }

    // 3. Back-post each unposted transaction
    $rows = $pdo->query("SELECT id, type, amount, transaction_date, reference_number, receipt_number, description
                           FROM petty_cash_transactions WHERE transaction_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) { echo "  ~ No unposted transactions.\n\nMigration complete.\n"; exit(0); }

    $upd = $pdo->prepare("UPDATE petty_cash_transactions
                          SET fund_account_id = ?, expense_account_id = ?, source_account_id = ?, transaction_id = ?, needs_review = 1
                          WHERE id = ?");
    $done = 0; $skipped = 0;
    foreach ($rows as $r) {
        $amount = (float)$r['amount'];
        $date   = $r['transaction_date'] ?: date('Y-m-d');
        $ref    = $r['reference_number'] ?: $r['receipt_number'] ?: null;
        $desc   = $r['description'] ?: '';

        if ($r['type'] === 'deposit') {
            if (!$obe) { $skipped++; continue; }   // no equity/funding account to credit
            $txn = postPettyCashLedger($pdo, 'deposit', $amount, $date, $ref, $desc, $obe, null, $fund);
            if ($txn) { $upd->execute([$fund, null, $obe, $txn, $r['id']]); $done++; }
            else $skipped++;
        } else { // expense
            if (!$uncat) { $skipped++; continue; }
            $txn = postPettyCashLedger($pdo, 'expense', $amount, $date, $ref, $desc, null, $uncat, $fund);
            if ($txn) { $upd->execute([$fund, $uncat, null, $txn, $r['id']]); $done++; }
            else $skipped++;
        }
    }
    echo "  + Back-posted $done legacy transaction(s); flagged for review. Skipped $skipped.\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
