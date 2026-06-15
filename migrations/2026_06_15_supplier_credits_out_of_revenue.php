<?php
/**
 * 2026_06_15_supplier_credits_out_of_revenue.php
 * ----------------------------------------------
 * Income-Statement classification (Area B). Supplier credits / debit-note refunds are
 * a PURCHASE-side item — under IFRS they reduce the cost of purchases; they are NEVER
 * sales revenue. In BMS the "Supplier Credit Notes" account was mis-classified as
 * `revenue`, so refunds received from suppliers inflated Revenue on the P&L.
 *
 * This re-points that account (resolved BY ROLE — the configured supplier-credits
 * account, else canonical code — never a hard-coded id/amount) from `revenue` → `cogs`,
 * so its historical credit balance is correctly presented as a REDUCTION of cost of
 * sales and leaves the Revenue section entirely.
 *
 * Going forward, new debit-note refunds are routed to Accounts Payable instead
 * (api/purchase/pay_debit_note.php), which nets the AP debit a purchase return raises
 * (OUT-8: Dr AP / Cr Inventory) — so future refunds neither hit income nor double-count
 * the cost reduction. This migration only fixes the historical presentation.
 *
 * Idempotent, additive, pure classification — no journal postings move, so the Balance
 * Sheet/Trial Balance are unaffected; only the P&L Revenue-vs-cost split changes.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/gl_accounts.php';   // cogsAccountId / gl_account_by_code
global $pdo;

echo "Starting migration: supplier credits out of Revenue → cost-of-sales reduction...\n";

try {
    // Resolve the supplier-credits account BY ROLE (setting → canonical code 4-9000).
    $accId = 0;
    $set = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_supplier_credits_account_id' AND setting_value REGEXP '^[0-9]+$' LIMIT 1")->fetchColumn();
    if ($set) $accId = (int)$set;
    if (!$accId) $accId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='4-9000' LIMIT 1")->fetchColumn() ?: 0);
    if (!$accId) { echo "  ~ no supplier-credits account configured/found — nothing to re-point.\n"; echo "Migration complete (no-op).\n"; return; }

    // The COGS account type (created by the earlier cogs/finance migration).
    $cogsType = (int)($pdo->query("SELECT type_id FROM account_types WHERE category='cogs' LIMIT 1")->fetchColumn() ?: 0);
    if (!$cogsType) { echo "  ! no 'cogs' account type present — run the COGS classification migration first. Aborting.\n"; exit(1); }

    // Current category — only re-point if it's still on the income side.
    $cur = $pdo->query("SELECT at.category FROM accounts a LEFT JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=" . (int)$accId)->fetchColumn();
    if ($cur === 'cogs') {
        echo "  ~ supplier-credits account (#$accId) already classified cogs — nothing to do.\n";
    } else {
        $st = $pdo->prepare("UPDATE accounts SET account_type_id = ? WHERE account_id = ?");
        $st->execute([$cogsType, $accId]);
        echo "  + re-pointed supplier-credits account (#$accId, was category='" . ($cur ?: 'null') . "') → cost-of-sales reduction (cogs).\n";
    }

    // Re-home it under the COST OF SALES branch so the chart tree stays class-consistent
    // (a cogs account must not hang under the Income/revenue branch). Resolve the branch
    // by canonical code (5-0000); if absent, leave the parent as-is.
    $cosBranch = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='5-0000' LIMIT 1")->fetchColumn() ?: 0);
    if ($cosBranch && $cosBranch !== $accId) {
        $st = $pdo->prepare("UPDATE accounts SET parent_account_id = ? WHERE account_id = ? AND (parent_account_id <> ? OR parent_account_id IS NULL)");
        $st->execute([$cosBranch, $accId, $cosBranch]);
        if ($st->rowCount()) echo "  + re-parented supplier-credits account under Cost of Sales (5-0000).\n";
        else echo "  ~ supplier-credits account already under the Cost of Sales branch.\n";
    }

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
