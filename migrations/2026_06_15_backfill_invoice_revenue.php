<?php
/**
 * 2026_06_15_backfill_invoice_revenue.php
 * ---------------------------------------
 * Income-Statement completeness (Area C). Revenue is recognised in the GL when an
 * invoice is APPROVED (IN-3: postInvoiceRevenue → Dr AR / Cr Sales [/ Cr Output VAT]).
 * Invoices that reached a recognised status BEFORE that routine existed carry no GL
 * revenue, so genuine sales are missing from the Income Statement.
 *
 * This recognises every earned-but-unposted invoice by criteria (status ≥ recognition
 * threshold AND no posted revenue entry), reusing the EXISTING idempotent engine:
 *   - postInvoiceRevenue() — idempotent on (entity_type='invoice', invoice_id); skips
 *     invoices recognised via an IPC (recognised_via_ipc) so nothing double-counts.
 *   - postInvoiceCOGS()    — matching cost (idempotent; skips POS-recognised) so gross
 *     profit stays correct.
 * Each posts dated to the invoice's own date. Criteria-based + idempotent: safe to
 * re-run on any DB (local/online), any volume, any customer — it only fills real gaps.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/revenue_posting.php';     // postInvoiceRevenue / postInvoiceCOGS
require_once __DIR__ . '/../core/financial_reports.php';   // assertLedgerBalanced (guardrail)
global $pdo;

echo "Starting migration: backfill revenue for earned-but-unposted invoices...\n";

try {
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);

    // Recognised status = approved onward (NOT draft/pending/reviewed/cancelled), AND
    // no posted revenue entry yet. Pure criteria — no ids, no amounts, no record lists.
    $rows = $pdo->query("
        SELECT i.invoice_id
          FROM invoices i
         WHERE i.status IN ('approved','partial','paid','overdue')
           AND NOT EXISTS (
               SELECT 1 FROM journal_entries je
                WHERE je.entity_type='invoice' AND je.entity_id=i.invoice_id AND je.status='posted')
      ORDER BY i.invoice_id
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "  found " . count($rows) . " recognised-status invoice(s) without posted revenue.\n";

    $posted = 0; $skipped = 0;
    foreach ($rows as $invId) {
        $invId = (int)$invId;
        $rev = postInvoiceRevenue($pdo, $invId, $uid);
        if (!empty($rev['posted']) && ($rev['reason'] ?? '') === 'posted') {
            $posted++;
            // Match the cost (best-effort, idempotent) so gross profit stays right.
            if (function_exists('postInvoiceCOGS')) { postInvoiceCOGS($pdo, $invId, $uid); }
        } else {
            $skipped++;   // already_posted / recognised_via_ipc / no_amount, etc.
        }
    }
    echo "  recognised $posted invoice(s); skipped $skipped (already posted / IPC-recognised / nil).\n";

    // Guardrail — books must still balance after the backfill.
    $g = assertLedgerBalanced($pdo);
    echo "  guardrail: ledger_balanced=" . ($g['ledger_balanced'] ? 'true' : 'false')
       . " bs_balanced=" . ($g['bs_balanced'] ? 'true' : 'false') . "\n";

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
