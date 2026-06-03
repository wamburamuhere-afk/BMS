<?php
/**
 * 2026_06_03_vat_control_reconcile.php
 * ------------------------------------
 * Self-heal the two VAT control-account balances so they exactly equal the VAT
 * actually posted on live documents.
 *
 * Each document records the precise VAT it posted (invoices.output_vat_posted /
 * supplier_invoices.input_vat_posted; NULL = not posted), and every approval
 * sets it while every reversal clears it. Therefore the true control-account
 * balance is the sum of those flags over live (non-deleted/non-cancelled) rows:
 *
 *     Output VAT Payable    = Σ invoices.output_vat_posted          (a liability)
 *     Input VAT Recoverable = Σ supplier_invoices.input_vat_posted  (an asset)
 *
 * This clears any legacy drift — e.g. a row deleted before the delete-reversal
 * logic existed, which would otherwise leave an orphaned balance. On a clean
 * install it is a no-op (the balances already equal the sums). Idempotent.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/vat.php';
global $pdo;

echo "Starting migration: reconcile VAT control-account balances...\n";

try {
    $outId = outputVatAccountId($pdo);
    $inId  = inputVatAccountId($pdo);
    if (!$outId || !$inId) {
        echo "  · VAT control accounts not configured yet — skipping.\n";
        echo "\nMigration complete.\n";
        return;
    }

    // Live posted totals (the authoritative position).
    $trueOut = (float)$pdo->query(
        "SELECT COALESCE(SUM(output_vat_posted),0) FROM invoices WHERE status <> 'cancelled'"
    )->fetchColumn();
    $trueIn = (float)$pdo->query(
        "SELECT COALESCE(SUM(input_vat_posted),0) FROM supplier_invoices WHERE status <> 'deleted'"
    )->fetchColumn();

    $beforeOut = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id = $outId")->fetchColumn();
    $beforeIn  = (float)$pdo->query("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id = $inId")->fetchColumn();

    $pdo->prepare("UPDATE accounts SET current_balance = ?, updated_at = NOW() WHERE account_id = ?")->execute([$trueOut, $outId]);
    $pdo->prepare("UPDATE accounts SET current_balance = ?, updated_at = NOW() WHERE account_id = ?")->execute([$trueIn, $inId]);

    printf("  Output VAT: %s -> %s%s\n", number_format($beforeOut, 2), number_format($trueOut, 2),
        abs($beforeOut - $trueOut) < 0.01 ? ' (already correct)' : ' (corrected drift)');
    printf("  Input  VAT: %s -> %s%s\n", number_format($beforeIn, 2), number_format($trueIn, 2),
        abs($beforeIn - $trueIn) < 0.01 ? ' (already correct)' : ' (corrected drift)');

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
