<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// Remediation for the pre-existing is_service COGS/Inventory bug (post_principle.md
// audit, 2026-07-26): before posSaleCogs()/invoiceCogsValue()/creditNoteRestockCost()/
// purchaseReturnValue() excluded is_service=1 products, some already-posted entries
// wrongly moved Dr COGS / Cr Inventory (or Dr AP / Cr Inventory for purchase returns)
// for a service. Never edits or deletes the original posted entry — posts a Style B
// contra-entry for exactly the overstated excess, matching this system's own
// documented principle (assertJournalNotPosted). Criteria-based (re-derives every
// corrupted entry from the ledger + the corrected functions) — no entry ids hard-coded,
// so this self-heals whatever exists in each environment and is idempotent (a
// '<entity_type>_isservice_correction' entry already posted for that entity is skipped).

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';
require_once __DIR__ . '/../core/gl_accounts.php';
require_once __DIR__ . '/../core/sales_posting.php';
require_once __DIR__ . '/../core/revenue_posting.php';
require_once __DIR__ . '/../core/purchase_posting.php';
global $pdo;

echo "Starting migration: correct pre-existing is_service COGS/Inventory overstatements...\n";

/**
 * Scan every posted entry of $entityType, recompute the true value via the
 * (now-fixed) $correctValueFn, and post a compensating entry for any excess.
 * Reads the ORIGINAL entry's own debit/credit accounts rather than assuming
 * fixed account ids, so it works for both COGS-shaped (Dr COGS/Cr Inventory)
 * and purchase-return-shaped (Dr AP/Cr Inventory) entries alike.
 */
function correctIsServiceOverstatement(PDO $pdo, string $entityType, callable $correctValueFn, int $userId): void
{
    $correctionType = $entityType . '_isservice_correction';
    $rows = $pdo->query("SELECT DISTINCT entity_id, entry_id FROM journal_entries WHERE entity_type = " . $pdo->quote($entityType) . " AND status = 'posted'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $entityId = (int)$r['entity_id'];
        $entryId  = (int)$r['entry_id'];

        $correct = round((float)$correctValueFn($pdo, $entityId), 2);
        $posted  = round((float)$pdo->query("SELECT SUM(amount) FROM journal_entry_items WHERE entry_id = $entryId AND type = 'debit'")->fetchColumn(), 2);
        $excess  = round($posted - $correct, 2);
        if ($excess <= 0.01) continue;   // not overstated (or already correct) — nothing to do

        $already = $pdo->prepare("SELECT 1 FROM journal_entries WHERE entity_type = ? AND entity_id = ? AND status = 'posted' LIMIT 1");
        $already->execute([$correctionType, $entityId]);
        if ($already->fetchColumn()) { echo "  $entityType #$entityId: already corrected — skipping.\n"; continue; }

        $legs = $pdo->query("SELECT account_id, type FROM journal_entry_items WHERE entry_id = $entryId")->fetchAll(PDO::FETCH_ASSOC);
        $debitAcc = null; $creditAcc = null;
        foreach ($legs as $l) {
            if ($l['type'] === 'debit')  $debitAcc  = (int)$l['account_id'];
            if ($l['type'] === 'credit') $creditAcc = (int)$l['account_id'];
        }
        if (!$debitAcc || !$creditAcc) { echo "  $entityType #$entityId: entry #$entryId is not a simple 2-leg entry — skipping (needs manual review).\n"; continue; }

        $hdr = $pdo->query("SELECT project_id FROM journal_entries WHERE entry_id = $entryId")->fetch(PDO::FETCH_ASSOC);
        $pid = !empty($hdr['project_id']) ? (int)$hdr['project_id'] : null;

        try {
            $correctionEntryId = postLedgerEntry(
                $pdo,
                "Correction: $entityType #$entityId over-posted " . number_format($posted, 2) . ", should be " . number_format($correct, 2) . " (is_service remediation, post_principle.md)",
                [
                    ['account_id' => $creditAcc, 'type' => 'debit',  'amount' => $excess, 'description' => 'Reverses is_service overstatement'],
                    ['account_id' => $debitAcc,  'type' => 'credit', 'amount' => $excess, 'description' => 'Reverses is_service overstatement'],
                ],
                $pid, $entityId, $correctionType, date('Y-m-d'), $userId
            );
            echo "  Corrected $entityType #$entityId (original entry #$entryId): excess " . number_format($excess, 2) . " reversed via entry #$correctionEntryId.\n";
        } catch (Throwable $e) {
            echo "  FAILED to correct $entityType #$entityId: " . $e->getMessage() . "\n";
        }
    }
}

try {
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

    correctIsServiceOverstatement($pdo, 'invoice_cogs',     'invoiceCogsValue',     $uid);
    correctIsServiceOverstatement($pdo, 'pos_cogs',         'posSaleCogs',          $uid);
    correctIsServiceOverstatement($pdo, 'credit_note_cogs', 'creditNoteRestockCost', $uid);
    correctIsServiceOverstatement($pdo, 'purchase_return',  'purchaseReturnValue',  $uid);

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
