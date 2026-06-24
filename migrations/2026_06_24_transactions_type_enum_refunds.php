<?php
/**
 * 2026_06_24_transactions_type_enum_refunds.php
 * -----------------------------------------------
 * Adds three missing transaction types to transactions.transaction_type ENUM.
 *
 * Why: On a STRICT MySQL server (production Ubuntu) an out-of-range ENUM value
 * is rejected with error 1265 "Data truncated". On a non-strict local WAMP it
 * is silently coerced to '' — so these "work" locally but crash on production.
 *
 *   'debit_note_refund'  — api/purchase/pay_debit_note.php (postInflowOrFail).
 *                          Production error: "The receipt could not be written
 *                          to the ledger — the double entry did not post."
 *
 *   'credit_note_refund' — api/sales/pay_credit_note.php (postOutflowOrFail).
 *                          Same crash when settling an approved credit note.
 *
 *   'petty_cash_topup'   — core/payment_source.php::postPettyCashLedger()
 *                          (deposit branch, recordGlobalTransaction).
 *                          Petty-cash top-up silently fails on production.
 *
 * Idempotent: reads the current ENUM, appends only the values that are absent,
 * never drops existing values. Safe to re-run on any server.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add refund + petty_cash_topup types to transactions.transaction_type ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM `transactions` LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  ! transactions.transaction_type not found — skipping.\n";
        echo "Migration complete.\n";
        return;
    }

    $type = $col['Type'];   // e.g. enum('a','b',...)
    if (!preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        echo "  ! column is not an ENUM ({$type}) — nothing to do.\n";
        echo "Migration complete.\n";
        return;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $vm[1]);

    $needed  = ['debit_note_refund', 'credit_note_refund', 'petty_cash_topup'];
    $missing = array_values(array_filter($needed, fn($v) => !in_array($v, $existing, true)));

    if (!$missing) {
        echo "  · all three types already present — no change.\n";
        echo "Migration complete.\n";
        return;
    }

    $all    = array_merge($existing, $missing);
    $quoted = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
    $notNull = ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';

    $pdo->exec("ALTER TABLE `transactions` MODIFY COLUMN `transaction_type` ENUM($quoted){$notNull}");
    echo "  + added: " . implode(', ', $missing) . "\n";
    echo "  + transaction_type now has " . count($all) . " values.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
