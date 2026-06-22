<?php
/**
 * HIGH #2 — legacy Bill/ledger cleanup (criteria-based, idempotent).
 *
 *  Part A — void malformed POSTED journal entries (< 2 legs). A double-entry must
 *           have at least one Dr and one Cr leg; a 0/1-leg posted header is junk.
 *
 *  Part B — backfill the MISSING supplier-invoice accrual (Dr Inventory / Cr AP)
 *           for goods Bills that never posted theirs, but ONLY the cases where it
 *           provably nets clean:
 *             • Bill is unpaid (raises the correct payable), OR
 *             • Bill's payment already debited AP (so the accrual nets AP to zero).
 *           Bills whose payment was mis-posted to a NON-AP account are FLAGGED and
 *           skipped — they need a deliberate manual correction, not a backfill.
 *
 * Uses postGoodsInvoiceAccrual() (idempotent on entity_type='supplier_invoice'),
 * so re-running never double-posts. Aborts if the ledger is left unbalanced.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/gl_accounts.php';
require_once __DIR__ . '/../core/purchase_posting.php';
global $pdo;

echo "Starting migration: HIGH#2 legacy Bill/ledger cleanup...\n";

try {
    // Precondition guard (order-independent): Part B calls postGoodsInvoiceAccrual(),
    // which SELECTs supplier_invoices.cost_account_id. The runner sorts migrations by
    // filename, so this cleanup ('bill_...') runs BEFORE the column migration
    // ('supplier_invoice_cost_account'). Ensure the column exists here so this
    // migration never depends on file order. Idempotent — no-op if already added.
    $hasCostAcc = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'cost_account_id'")->fetchColumn();
    if (!$hasCostAcc) {
        $pdo->exec("ALTER TABLE supplier_invoices ADD COLUMN cost_account_id INT NULL DEFAULT NULL AFTER amount");
        echo "  ensured supplier_invoices.cost_account_id exists (added now).\n";
    }

    $uid = (int)($pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn()
            ?: ($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1));
    $ap  = apAccountId($pdo);
    if (!$ap) { echo "AP account not resolved — aborting.\n"; exit(1); }

    // ── Part A: void malformed posted entries (< 2 legs) ──────────────────────
    $malformed = $pdo->query("
        SELECT je.entry_id
          FROM journal_entries je
          LEFT JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
         WHERE je.status = 'posted'
         GROUP BY je.entry_id
        HAVING COUNT(jei.item_id) < 2
    ")->fetchAll(PDO::FETCH_COLUMN);
    $voided = 0;
    foreach ($malformed as $eid) {
        $pdo->prepare("UPDATE journal_entries SET status='void', updated_at=NOW() WHERE entry_id=? AND status='posted'")
            ->execute([(int)$eid]);
        echo "  Part A: voided malformed posted entry #$eid (< 2 legs)\n";
        $voided++;
    }
    echo "  Part A: voided $voided malformed entr" . ($voided === 1 ? 'y' : 'ies') . ".\n";

    // ── Part B: backfill missing accruals for the clean cases only ────────────
    $bills = $pdo->query("
        SELECT id, invoice_ref, amount, amount_paid, payment_transaction_id
          FROM supplier_invoices
         WHERE invoice_type = 'supplier' AND status IN ('approved','partial','paid')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $posted = 0; $alreadyAccrued = 0; $flagged = 0;
    foreach ($bills as $b) {
        $id = (int)$b['id'];

        $has = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries
                                 WHERE entity_type='supplier_invoice' AND entity_id=$id AND status='posted'")->fetchColumn();
        if ($has) { $alreadyAccrued++; continue; }

        // Did any payment for this Bill debit AP? (new subledger + legacy single path)
        $legacyTxn = (int)($b['payment_transaction_id'] ?: 0);
        $apDebit = (float)$pdo->query("
            SELECT COALESCE(SUM(bt.amount),0)
              FROM books_transactions bt
             WHERE bt.type='debit' AND bt.account_id=$ap
               AND ( bt.transaction_id IN (SELECT journal_txn_id FROM supplier_invoice_payments WHERE invoice_id=$id)
                     OR bt.transaction_id = $legacyTxn )
        ")->fetchColumn();

        $unpaid    = ((float)$b['amount_paid'] <= 0.01);
        $qualifies = $unpaid || ($apDebit > 0.01);

        if (!$qualifies) {
            echo "  Part B: FLAGGED #{$b['invoice_ref']} (id $id) — paid but payment did not debit AP; skipped for manual correction.\n";
            $flagged++;
            continue;
        }

        $res = postGoodsInvoiceAccrual($pdo, $id, $uid);
        if (!empty($res['entry_id'])) {
            echo "  Part B: backfilled #{$b['invoice_ref']} (id $id) amount " . number_format((float)$b['amount'], 2) . " → entry #{$res['entry_id']}\n";
            $posted++;
        } else {
            echo "  Part B: #{$b['invoice_ref']} (id $id) — {$res['reason']}, no new entry\n";
        }
    }
    echo "  Part B: backfilled $posted, already-accrued $alreadyAccrued, flagged $flagged.\n";

    // ── Verify the ledger still balances ──────────────────────────────────────
    $r = $pdo->query("
        SELECT COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END),0) dr,
               COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
          FROM journal_entry_items jei
          JOIN journal_entries je ON je.entry_id = jei.entry_id
         WHERE je.status='posted'
    ")->fetch(PDO::FETCH_ASSOC);
    $diff = round((float)$r['dr'] - (float)$r['cr'], 2);
    echo "  Verify: Σ Dr=" . number_format($r['dr'], 2) . "  Σ Cr=" . number_format($r['cr'], 2) . "  diff=$diff " . (abs($diff) < 0.01 ? '[BALANCED]' : '[*** OUT ***]') . "\n";
    if (abs($diff) >= 0.01) { echo "Ledger out of balance after cleanup — aborting.\n"; exit(1); }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
