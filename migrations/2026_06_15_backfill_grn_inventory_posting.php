<?php
/**
 * 2026_06_15_backfill_grn_inventory_posting.php
 * -----------------------------------------------
 * COGS Fix 2 — backfill GL inventory postings for approved GRNs.
 *
 * ROOT CAUSE
 * money.md OUT-7 wires GRN approval to postGrnReceipt() (core/purchase_posting.php),
 * which posts Dr Inventory (1-1300) / Cr Accounts Payable. GRNs approved BEFORE that
 * wiring was deployed carry no GL entry — so the Balance Sheet Inventory account reads
 * zero despite real goods having been received and stocked.
 *
 * NOTE ON COGS
 * This fix targets the Balance Sheet (Inventory / AP), NOT the IS COGS section.
 * In BMS's perpetual inventory model, goods-received posts to Inventory (asset).
 * COGS only moves when inventory is consumed at invoice approval (postInvoiceCOGS).
 * 5-2000 Purchases / 5-3000 Freight are periodic-system accounts and are not posted
 * to in this flow.
 *
 * DETECTION CRITERIA (dataset-agnostic, never hard-coded IDs)
 *   purchase_receipts.status = 'approved'
 *   AND no posted journal_entries with entity_type='grn' AND entity_id=receipt_id
 *
 * WHAT THIS MIGRATION DOES
 * For every approved GRN missing a GL entry, calls postGrnReceipt() — the same
 * function approve_grn.php uses — with the GRN's own date and reference.
 * postGrnReceipt() is already idempotent on (entity_type='grn', entity_id=receipt_id),
 * so re-running this migration is a no-op for already-posted GRNs.
 * GRNs with zero receipt value (no items or all qty=0) are skipped and reported.
 *
 * IFRS BASIS
 * IAS 2 §10 — inventories are recognised at cost when control passes (goods received).
 * GRN approval is the control-transfer event; the GL must reflect it.
 *
 * SAFE TO RE-RUN — idempotent; re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/purchase_posting.php';   // postGrnReceipt, grnReceiptValue
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

echo "Starting migration: backfill GL inventory posting for approved GRNs (OUT-7)...\n";

try {
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
    if ($uid <= 0) {
        echo "  ! No users found — cannot resolve posting user. Aborting.\n";
        exit(1);
    }

    // ── Detect approved GRNs with no posted GL entry ──────────────────────────
    // Criteria only: status='approved' + no posted entity_type='grn' entry.
    // Never references a receipt_id by value — the query finds them dynamically.
    $candidates = $pdo->query("
        SELECT
            pr.receipt_id,
            pr.receipt_number,
            pr.receipt_date,
            pr.project_id
          FROM purchase_receipts pr
         WHERE pr.status = 'approved'
           AND NOT EXISTS (
               SELECT 1
                 FROM journal_entries je
                WHERE je.entity_type = 'grn'
                  AND je.entity_id   = pr.receipt_id
                  AND je.status      = 'posted'
           )
         ORDER BY pr.receipt_date, pr.receipt_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  detected " . count($candidates) . " approved GRN(s) with no GL posting.\n";

    if (empty($candidates)) {
        echo "  ~ nothing to backfill.\n";
    }

    // ── Backfill each GRN via the same function approve_grn.php uses ──────────
    $posted  = 0;
    $skipped = 0;
    $errors  = 0;

    foreach ($candidates as $grn) {
        $receiptId  = (int)$grn['receipt_id'];
        $reference  = $grn['receipt_number'];
        $date       = substr((string)$grn['receipt_date'], 0, 10);
        $projectId  = $grn['project_id'] !== null ? (int)$grn['project_id'] : null;

        // Compute value from receipt_items — same as approve_grn.php, never trusts
        // the denormalised total_received column.
        $grnTotal = grnReceiptValue($pdo, $receiptId);

        $result = postGrnReceipt($pdo, $receiptId, $grnTotal, $date, $projectId, $uid, $reference);

        if ($result['posted'] && $result['reason'] === 'posted') {
            echo "  + posted: GRN {$reference} (id={$receiptId})"
               . " value=" . number_format($grnTotal, 2)
               . " dated={$date}\n";
            $posted++;
        } elseif ($result['reason'] === 'already_posted') {
            // postGrnReceipt's own idempotency guard — shouldn't reach here given
            // the detection query, but handle it safely.
            echo "  ~ already posted: GRN {$reference} (id={$receiptId}) — skipped.\n";
            $skipped++;
        } elseif ($result['reason'] === 'no_amount') {
            echo "  ~ zero value: GRN {$reference} (id={$receiptId})"
               . " — no receipt items with quantity/price, skipped.\n";
            $skipped++;
        } elseif ($result['reason'] === 'accounts_not_configured') {
            echo "  ! GRN {$reference}: Inventory or AP account not configured"
               . " — check gl_accounts / system_settings.\n";
            $errors++;
        } else {
            echo "  ! GRN {$reference} (id={$receiptId}): {$result['reason']}\n";
            $errors++;
        }
    }

    echo "  result: {$posted} posted, {$skipped} skipped (zero-value or already done),"
       . " {$errors} error(s).\n";

    if ($errors > 0) {
        echo "  ! {$errors} GRN(s) could not be posted — review errors above before deploying.\n";
        exit(1);
    }

    // ── Balance guardrail ─────────────────────────────────────────────────────
    $g = assertLedgerBalanced($pdo);
    $ledgerOk = $g['ledger_balanced'] ?? false;
    $bsOk     = $g['bs_balanced']     ?? false;
    echo "  guardrail: ledger_balanced=" . ($ledgerOk ? 'true' : 'false')
       . " bs_balanced="                . ($bsOk     ? 'true' : 'false') . "\n";

    if (!$ledgerOk) {
        echo "  ! LEDGER OUT OF BALANCE after migration — investigate before deploying.\n";
        exit(1);
    }

    echo "\nMigration complete.\n";

} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
