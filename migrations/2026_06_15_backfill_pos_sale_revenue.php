<?php
/**
 * 2026_06_15_backfill_pos_sale_revenue.php
 * -----------------------------------------
 * IN-5 (money.md) — backfill the canonical-ledger postings for POS sales that were
 * completed BEFORE the GL wiring was deployed.
 *
 * BACKGROUND
 * core/sales_posting.php::postPosSale() (wired into api/pos/process_sale.php) posts a
 * completed POS sale to journal_entries as two balanced double-entries:
 *     1. Revenue  Dr Cash/Bank (collected) + Dr AR (balance)  /  Cr Sales Revenue (net of VAT)  /  Cr Output VAT
 *     2. COGS     Dr Cost of Goods Sold  /  Cr Inventory   (Σ qty × products.cost_price)
 * Sales transacted before that wiring carry NO GL entry — so their revenue, the 18%
 * Output VAT (TRA/EFD), and the inventory/COGS movement are all absent from the books.
 *
 * WHAT THIS MIGRATION DOES
 * For every POS sale that genuinely transacted but has no posted 'pos_sale' entry, it
 * calls the SAME function process_sale.php uses — postPosSale() — with the sale's own
 * date, payment method, project and receipt number. postPosSale() is idempotent on
 * (entity_type='pos_sale'|'pos_cogs', entity_id=sale_id), so re-running is a no-op and
 * any sale already posted (e.g. created after the wiring) is skipped automatically.
 *
 * DETECTION CRITERIA (dataset-agnostic — never hard-coded IDs)
 *   pos_sales.is_return_sale = 0 (or NULL)            — a real sale, not a return row (returns = IN-6)
 *   AND pos_sales.sale_status IN ('completed','partially_refunded','refunded')
 *       — statuses that represent a sale that actually happened (control transferred).
 *         'partially_refunded'/'refunded' still recognised the original revenue at sale;
 *         their refund contra is a separate entry (IN-6). draft/pending/on_hold/cancelled/
 *         voided never transacted and are excluded.
 *   AND no posted journal_entries with entity_type='pos_sale' AND entity_id=sale_id
 *
 * CASH-vs-CREDIT RECONSTRUCTION (per sale, from the most authoritative source)
 *   payment_status='paid'    → cashPaid = grand_total, balanceDue = 0      (collected in full)
 *   payment_status='partial' → cashPaid = Σ pos_sale_payments, balance = grand_total − cashPaid
 *   payment_status else      → cashPaid = Σ pos_sale_payments (usually 0), balance = the rest
 * postPosSale() also defensively reconciles cashPaid+balanceDue to grand_total.
 *
 * IFRS / TANZANIA BASIS
 *   IFRS 15 — revenue recognised when control transfers (point of sale).
 *   IAS 2   — cost of goods leaves inventory into COGS at the same point.
 *   VAT Act 2014 + EFD Regs — 18% Output VAT on each fiscalised POS sale must hit the GL,
 *             so the ledger reconciles to TRA's EFD record.
 *
 * SAFE TO RE-RUN — idempotent per sale; re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/sales_posting.php';      // postPosSale, posSaleCogs
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

echo "Starting migration: backfill POS sale revenue + COGS (IN-5)...\n";

try {
    // ── Resolve posting user (lowest user_id — migration context, not a real user) ──
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
    if ($uid <= 0) {
        echo "  ! No users found — cannot resolve posting user. Aborting.\n";
        exit(1);
    }

    // ── Detect transacted POS sales with no posted revenue entry ──────────────
    // Criteria only (no ids): real sale (not a return) + a transacted status +
    // no posted 'pos_sale' journal entry. Reconstructs the collected amount inline.
    $candidates = $pdo->query("
        SELECT
            ps.sale_id,
            ps.receipt_number,
            ps.sale_date,
            ps.project_id,
            ps.payment_method,
            ps.payment_status,
            ps.grand_total,
            ps.tax_amount,
            COALESCE((SELECT SUM(pp.amount) FROM pos_sale_payments pp WHERE pp.sale_id = ps.sale_id), 0) AS collected
          FROM pos_sales ps
         WHERE (ps.is_return_sale = 0 OR ps.is_return_sale IS NULL)
           AND ps.sale_status IN ('completed','partially_refunded','refunded')
           AND (
                 -- missing the revenue entry, OR
                 NOT EXISTS (
                     SELECT 1 FROM journal_entries je
                      WHERE je.entity_type = 'pos_sale'
                        AND je.entity_id   = ps.sale_id
                        AND je.status      = 'posted'
                 )
                 -- has real product cost but is missing the COGS entry (self-healing
                 -- if a prior run posted revenue but not COGS). Service-only sales with
                 -- no cost never re-trigger, so a fully-done dataset reports zero.
                 OR (
                     NOT EXISTS (
                         SELECT 1 FROM journal_entries je2
                          WHERE je2.entity_type = 'pos_cogs'
                            AND je2.entity_id   = ps.sale_id
                            AND je2.status      = 'posted'
                     )
                     AND EXISTS (
                         SELECT 1 FROM pos_sale_items si
                           JOIN products p ON p.product_id = si.product_id
                          WHERE si.sale_id = ps.sale_id
                            AND COALESCE(p.cost_price, 0) > 0
                     )
                 )
           )
         ORDER BY ps.sale_date, ps.sale_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  detected " . count($candidates) . " POS sale(s) with no GL revenue posting.\n";

    if (empty($candidates)) {
        echo "  ~ nothing to backfill.\n";
    }

    // ── Backfill each via the same function process_sale.php uses ─────────────
    $posted   = 0;   // revenue leg posted
    $cogsOnly = 0;   // counted separately for visibility (revenue + cogs both reported)
    $skipped  = 0;
    $errors   = 0;

    foreach ($candidates as $s) {
        $saleId    = (int)$s['sale_id'];
        $reference = $s['receipt_number'];
        $date      = substr((string)$s['sale_date'], 0, 10);
        $projectId = $s['project_id'] !== null ? (int)$s['project_id'] : null;
        $method    = (string)($s['payment_method'] ?: 'cash');
        $grand     = round((float)$s['grand_total'], 2);
        $tax       = round((float)$s['tax_amount'], 2);
        $collected = round((float)$s['collected'], 2);

        // Reconstruct cash-vs-credit split from the authoritative payment_status.
        if ($s['payment_status'] === 'paid') {
            $cashPaid   = $grand;
            $balanceDue = 0.0;
        } else {
            $cashPaid   = max(0.0, min($collected, $grand));
            $balanceDue = round($grand - $cashPaid, 2);
        }

        $res = postPosSale(
            $pdo, $saleId, $method, $cashPaid, $balanceDue,
            $grand, $tax, $date, $reference, $projectId, $uid
        );

        if (!empty($res['revenue'])) {
            $cogsTag = !empty($res['cogs']) ? ' +COGS' : ' (no product cost)';
            echo "  + posted: POS sale {$reference} (id={$saleId})"
               . " grand=" . number_format($grand, 2)
               . " cash=" . number_format($cashPaid, 2)
               . " credit=" . number_format($balanceDue, 2)
               . $cogsTag . " dated={$date}\n";
            $posted++;
            if (!empty($res['cogs'])) $cogsOnly++;
        } elseif (($res['reason'] ?? '') === 'already_posted') {
            echo "  ~ already posted: POS sale {$reference} (id={$saleId}) — skipped.\n";
            $skipped++;
        } elseif (($res['reason'] ?? '') === 'no_sale') {
            echo "  ~ zero-value sale: POS sale {$reference} (id={$saleId}) — skipped.\n";
            $skipped++;
        } else {
            echo "  ! POS sale {$reference} (id={$saleId}) did NOT post: " . ($res['reason'] ?: 'unknown') . "\n";
            $errors++;
        }
    }

    echo "  result: {$posted} revenue entr(ies) posted ({$cogsOnly} with COGS),"
       . " {$skipped} skipped, {$errors} error(s).\n";

    if ($errors > 0) {
        echo "  ! {$errors} sale(s) could not be posted — review the reasons above"
           . " (usually a control account not configured). Re-run after fixing.\n";
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
