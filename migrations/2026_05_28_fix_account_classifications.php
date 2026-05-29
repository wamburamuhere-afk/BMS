<?php
/**
 * 2026_05_28_fix_account_classifications.php
 * -------------------------------------------
 * Phase 0.2 — clean up the 3 mis-classified accounts so the canonical
 * ledger (Trial Balance, General Ledger, BS, IS) reads correct totals
 * per IFRS for SMEs statement category.
 *
 * SAFETY MODEL
 * ────────────
 * Every UPDATE is guarded by both:
 *   - exact account_name match (no LIKE) — won't catch unrelated rows
 *   - current account_type_id matches the known-wrong value — won't
 *     overwrite a row already corrected manually
 *
 * Re-run safe (idempotent): once an account has the right type the
 * UPDATE matches 0 rows.
 *
 * Account type IDs (from account_types):
 *   1 = asset      (BS, debit-natural)
 *   2 = liability  (BS, credit-natural)
 *   3 = equity     (BS, credit-natural)
 *   4 = income     (IS, credit-natural)
 *   5 = expense    (IS, debit-natural)
 *
 * THE 3 FIXES
 * ───────────
 *   id=2  "opening balance equit"  type 1 (asset)   → 3 (equity)
 *         + typo fix: name → "Opening Balance Equity"
 *   id=3  "Fixed Assets"           type 4 (income)  → 1 (asset)
 *   id=6  "NMB"                    type 5 (expense) → 1 (asset)
 *         (NMB is a Tanzanian bank; should be an asset account)
 *
 * Accounts NOT touched (already correct):
 *   id=4  "Salaries and Wages"        expense ✓
 *   id=5,9,12 "CRDB Bank - Main…"     asset   ✓
 *   id=13 "Marketing"                 expense ✓
 *
 * NO BEHAVIOUR CHANGE for callers — only account_type_id values change.
 * Opening balances + current balances are untouched.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: re-classify miscategorised accounts...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ! accounts table missing — cannot proceed.\n";
        exit(1);
    }

    // ── Fix 1: "opening balance equit" — wrong type (asset → equity) + typo
    $stmt = $pdo->prepare("
        UPDATE accounts
           SET account_type_id = 3,
               account_name    = 'Opening Balance Equity',
               updated_at      = NOW()
         WHERE account_name = 'opening balance equit'
           AND account_type_id = 1
    ");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "  + Fixed: 'opening balance equit' (asset → equity) + typo corrected.\n";
    } else {
        echo "  · 'opening balance equit' already corrected (or not present), skipping.\n";
    }

    // ── Fix 2: "Fixed Assets" — income → asset
    $stmt = $pdo->prepare("
        UPDATE accounts
           SET account_type_id = 1,
               updated_at      = NOW()
         WHERE account_name = 'Fixed Assets'
           AND account_type_id = 4
    ");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "  + Fixed: 'Fixed Assets' (income → asset).\n";
    } else {
        echo "  · 'Fixed Assets' already corrected (or not present), skipping.\n";
    }

    // ── Fix 3: "NMB" (bank) — expense → asset
    $stmt = $pdo->prepare("
        UPDATE accounts
           SET account_type_id = 1,
               updated_at      = NOW()
         WHERE account_name = 'NMB'
           AND account_type_id = 5
    ");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "  + Fixed: 'NMB' (expense → asset; NMB is a Tanzanian bank).\n";
    } else {
        echo "  · 'NMB' already corrected (or not present), skipping.\n";
    }

    // ── Verification: report post-state ────────────────────────────────────
    echo "\nPost-migration state of the 3 target accounts:\n";
    $rows = $pdo->query("
        SELECT a.account_id, a.account_name, a.account_type_id, at.type_name
          FROM accounts a
     LEFT JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.account_id IN (2, 3, 6)
      ORDER BY a.account_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        printf("    id=%-3d  %-30s  type=%s\n",
            $r['account_id'], $r['account_name'], $r['type_name']);
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
