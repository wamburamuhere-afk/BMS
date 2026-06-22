<?php
/**
 * PHASE 2 — remediate Input VAT buried inside the cost on OLD Bill accruals.
 *
 * Before the Phase-1 fix, a Bill accrual debited the cost account (Inventory/COGS)
 * the GROSS amount (VAT included) with no Input VAT line. This migration moves the
 * VAT out, for every such posted entry, by posting a balanced reclassification:
 *
 *     Dr Input VAT Recoverable (tax)  /  Cr <original cost account> (tax)
 *
 * → cost drops to net, Input VAT appears on the Balance Sheet, AP untouched.
 *
 * Criteria-based + idempotent (skips entries already split or already remediated).
 * Uses remediateBuriedVatForEntry() — the same helper the test exercises. Aborts
 * if the ledger is left unbalanced.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/gl_accounts.php';
require_once __DIR__ . '/../core/purchase_posting.php';
global $pdo;

echo "Starting migration: remediate buried Input VAT on Bill accruals (Phase 2)...\n";

try {
    $uid = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn()
            ?: ($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1));
    $vat = inputVatAccountId($pdo);
    if (!$vat) { echo "Input VAT account not resolved — nothing to do.\n"; echo "Migration complete.\n"; return; }

    // Candidate entries: posted Bill accruals whose bill carries VAT and that do
    // NOT already have an Input VAT line (i.e. old gross-style entries).
    $rows = $pdo->query("
        SELECT je.entry_id
          FROM journal_entries je
          JOIN supplier_invoices si ON si.id = je.entity_id
         WHERE je.status = 'posted'
           AND je.entity_type IN ('supplier_invoice','subcontractor_invoice')
           AND COALESCE(si.tax_amount,0) > 0
           AND NOT EXISTS (SELECT 1 FROM journal_entry_items v WHERE v.entry_id = je.entry_id AND v.account_id = " . (int)$vat . ")
         ORDER BY je.entry_id
    ")->fetchAll(PDO::FETCH_COLUMN);

    $done = 0; $skip = 0;
    foreach ($rows as $eid) {
        $res = remediateBuriedVatForEntry($pdo, (int)$eid, $uid);
        if (!empty($res['done']) && ($res['reason'] ?? '') === 'remediated') {
            echo "  entry #$eid → reclassified VAT (new entry #{$res['entry_id']})\n";
            $done++;
        } else {
            echo "  entry #$eid → skipped ({$res['reason']})\n";
            $skip++;
        }
    }
    echo "  remediated $done, skipped $skip, of " . count($rows) . " candidate(s).\n";

    // Verify balance.
    $r = $pdo->query("
        SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE 0 END),0) dr,
               COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
          FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
         WHERE je.status='posted'
    ")->fetch(PDO::FETCH_ASSOC);
    $diff = round((float)$r['dr'] - (float)$r['cr'], 2);
    echo "  Verify: Σ Dr=" . number_format($r['dr'],2) . " Σ Cr=" . number_format($r['cr'],2) . " diff=$diff " . (abs($diff) < 0.01 ? '[BALANCED]' : '[*** OUT ***]') . "\n";
    if (abs($diff) >= 0.01) { echo "Ledger out of balance after remediation — aborting.\n"; exit(1); }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
