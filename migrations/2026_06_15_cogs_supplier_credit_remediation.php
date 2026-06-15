<?php
/**
 * 2026_06_15_cogs_supplier_credit_remediation.php
 * ------------------------------------------------
 * COGS Fix 1 — remove supplier-credit entries from the IS COGS section.
 *
 * ROOT CAUSE
 * Area B (2026_06_15_supplier_credits_out_of_revenue.php) correctly moved the
 * "Supplier Credit Notes" account from `revenue` → `cogs` but did NOT post the
 * per-record contra journals required by the plan. As a result, 6 historical
 * debit-note payment entries (all credits) sit in the COGS bucket producing a
 * massive negative balance (−829 M locally) that inflates Gross Profit to a
 * nonsensical figure.
 *
 * WHAT THIS MIGRATION DOES
 * 1. DETECT — find every posted credit entry on an account that is BOTH:
 *      • account_types.category = 'cogs'   (Area B moved it here)
 *      • account_code LIKE '4-%'           (structurally impossible for a cost account)
 *    This structural invariant is true on any server/dataset: a revenue-range account
 *    (4-xxxx) can never legitimately be classified cogs. No account_id, no name, no
 *    amount is hard-coded.
 *
 * 2. REMEDIATE — for each detected credit item, post a dated contra journal:
 *      Dr [supplier-credits account]  /  Cr [Accounts Payable]
 *    dated to the ORIGINAL entry date (auditable, reversible). This zeroes out the
 *    supplier-credits account on the IS and correctly places the 829 M on the BS as
 *    an AP credit (the supplier's cash payment netted against our AP balance).
 *    Idempotent key: entity_type='cogs_supplier_credit_remediation', entity_id=item_id.
 *
 * 3. RE-CLASSIFY — move every account matching criteria (cogs + 4-xxxx) to the
 *    `asset` account type (supplier credit = claim against supplier = receivable = asset).
 *    After step 2 the account carries zero balance, so it will not appear in the BS
 *    either. This permanently removes it from the IS COGS section on every future run.
 *
 * 4. GUARDRAIL — assertLedgerBalanced() must pass after all contra entries are posted.
 *
 * IFRS BASIS
 * IAS 2 §10-22 — cost of inventory excludes supplier refunds; those reduce AP (BS).
 * Tanzania NBAA adopted IFRS without modification (Technical Pronouncement No. 3).
 *
 * SAFE TO RE-RUN — idempotent per detected item; re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/gl_accounts.php';       // apAccountId()
require_once __DIR__ . '/../core/ledger_post.php';        // postLedgerEntry()
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced()
global $pdo;

echo "Starting migration: COGS supplier-credit remediation...\n";

try {
    // ── Resolve AP account by role (never by hard-coded id) ──────────────────
    $apId = (int)(apAccountId($pdo) ?? 0);
    if ($apId <= 0) {
        echo "  ! Accounts Payable account not resolved — check gl_accounts / system_settings. Aborting.\n";
        exit(1);
    }
    echo "  AP account resolved: id={$apId}\n";

    // ── Resolve posting user (lowest user_id — migration context, not a real user) ─
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
    if ($uid <= 0) {
        echo "  ! No users found — cannot resolve posting user. Aborting.\n";
        exit(1);
    }

    // ── STEP 1 — Detect wrong entries ────────────────────────────────────────
    // Structural invariant: posted CREDIT items on accounts that are BOTH
    // category='cogs' AND account_code LIKE '4-%'. This combination is
    // definitionally wrong on any server. No ids, no names, no amounts used.
    $candidates = $pdo->query("
        SELECT
            jei.item_id,
            jei.account_id,
            jei.amount,
            je.entry_date,
            je.project_id,
            a.account_code,
            a.account_name
          FROM journal_entry_items jei
          JOIN journal_entries     je  ON je.entry_id  = jei.entry_id
                                      AND je.status    = 'posted'
          JOIN accounts            a   ON a.account_id = jei.account_id
          JOIN account_types       at  ON at.type_id   = a.account_type_id
         WHERE jei.type      = 'credit'
           AND at.category   = 'cogs'
           AND a.account_code LIKE '4-%'
         ORDER BY je.entry_date, jei.item_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  detected " . count($candidates) . " wrong credit item(s) in COGS (4-xxxx accounts).\n";

    if (empty($candidates)) {
        echo "  ~ no wrong entries found — nothing to remediate.\n";
    }

    // ── STEP 2 — Post contra journals (idempotent per item) ──────────────────
    $posted  = 0;
    $skipped = 0;

    foreach ($candidates as $item) {
        $itemId    = (int)$item['item_id'];
        $accountId = (int)$item['account_id'];
        $amount    = (float)$item['amount'];
        $date      = $item['entry_date'];
        $projectId = $item['project_id'] !== null ? (int)$item['project_id'] : null;
        $code      = $item['account_code'];
        $name      = $item['account_name'];

        // Idempotency check — skip if we already posted a contra for this item
        $already = $pdo->prepare("
            SELECT entry_id FROM journal_entries
             WHERE entity_type = 'cogs_supplier_credit_remediation'
               AND entity_id   = ?
               AND status      = 'posted'
             LIMIT 1
        ");
        $already->execute([$itemId]);
        if ($already->fetchColumn()) {
            $skipped++;
            continue;
        }

        if ($amount <= 0) {
            echo "  ~ skipping item_id={$itemId} (amount={$amount} — zero/negative, nothing to reverse).\n";
            $skipped++;
            continue;
        }

        // Contra: Dr [supplier-credits account] / Cr [AP]
        // Net effect on the books: the original wrong Dr Bank / Cr 4-9000 +
        // this contra Dr 4-9000 / Cr AP = Dr Bank / Cr AP — cash received,
        // AP reduced (correct treatment for a supplier refund). The IS is clean.
        postLedgerEntry(
            $pdo,
            "Remediation: remove supplier credit from COGS — {$code} {$name}",
            [
                ['account_id' => $accountId, 'type' => 'debit',  'amount' => $amount],
                ['account_id' => $apId,       'type' => 'credit', 'amount' => $amount],
            ],
            $projectId,
            $itemId,                              // entity_id = source item being remediated
            'cogs_supplier_credit_remediation',   // idempotency key type
            $date,                                // dated to original entry (auditable)
            $uid
        );

        echo "  + contra posted: Dr {$code} / Cr AP — amount=" . number_format($amount, 2)
           . " dated={$date} (item_id={$itemId})\n";
        $posted++;
    }

    echo "  remediated: {$posted} posted, {$skipped} skipped (already done or zero).\n";

    // ── STEP 3 — Re-classify accounts: cogs + 4-xxxx → asset ────────────────
    // After step 2 they carry zero balance. Moving to `asset` is IFRS-correct
    // (supplier credit = receivable from supplier) and permanently removes them
    // from every IS section. Resolved by category, never by type_id literal.
    $assetTypeId = (int)($pdo->query("
        SELECT type_id FROM account_types
         WHERE category = 'asset'
         ORDER BY type_id LIMIT 1
    ")->fetchColumn() ?: 0);

    if (!$assetTypeId) {
        echo "  ! No 'asset' account type found — cannot re-classify. Aborting.\n";
        exit(1);
    }

    $reclass = $pdo->prepare("
        UPDATE accounts a
          JOIN account_types at ON at.type_id = a.account_type_id
           SET a.account_type_id    = ?,
               a.parent_account_id  = NULL
         WHERE at.category    = 'cogs'
           AND a.account_code LIKE '4-%'
    ");
    $reclass->execute([$assetTypeId]);
    $reclassCount = $reclass->rowCount();

    if ($reclassCount > 0) {
        echo "  + re-classified {$reclassCount} account(s) from cogs → asset"
           . " (type_id={$assetTypeId}), parent cleared: removed from IS permanently.\n";
    } else {
        echo "  ~ no accounts needed re-classification (already asset or none matched).\n";
    }

    // ── STEP 3b — Fix parent nesting: asset accounts must not sit under a cogs parent ──
    // After step 3, any re-classified account may still be parented under a cogs-class
    // account (e.g. 5-0000 Cost of Sales), violating the same-class nesting rule.
    // Detect by role: parent's category != child's category → clear the parent (set NULL).
    // Criteria-based: no ids, works on any server regardless of tree shape.
    $reparent = $pdo->prepare("
        UPDATE accounts child
          JOIN account_types child_at ON child_at.type_id   = child.account_type_id
          JOIN accounts      parent   ON parent.account_id  = child.parent_account_id
          JOIN account_types par_at   ON par_at.type_id     = parent.account_type_id
           SET child.parent_account_id = NULL
         WHERE child_at.category  = 'asset'
           AND par_at.category   != 'asset'
           AND child.account_code LIKE '4-%'
    ");
    $reparent->execute();
    $reparentCount = $reparent->rowCount();
    if ($reparentCount > 0) {
        echo "  + cleared mismatched parent for {$reparentCount} account(s)"
           . " (asset account was under a non-asset parent).\n";
    } else {
        echo "  ~ parent nesting already clean — no mismatches found.\n";
    }

    // ── STEP 4 — Balance guardrail ────────────────────────────────────────────
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

} catch (LedgerException $e) {
    echo "  ! Ledger validation error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
