<?php
/**
 * 2026_06_23_transactions_type_enum_journal.php
 * ---------------------------------------------
 * Adds the 'journal' value to transactions.transaction_type.
 *
 * Why: the manual-journal endpoints (api/account/save_journal.php,
 * add_compound_journal.php, reverse_journal.php) post a ledger transaction with
 * transaction_type='journal' via recordGlobalTransaction(). The ENUM did not
 * include 'journal'. On a NON-strict server (typical local WAMP) the value is
 * silently coerced to '' and the insert "succeeds" — so journals appear to save
 * locally (but with a blank type). On a STRICT server (production) MySQL rejects
 * it with error 1265 "Data truncated for column 'transaction_type'", so the user
 * sees "Global Transaction Recording Failed" and cannot create a journal.
 * Adding 'journal' fixes it on every server.
 *
 * Also backfills the blank-type rows that the silent local truncation produced
 * for journal entries (criteria-based: empty type + a JRNL-/REV- reference), so
 * those rows carry the correct 'journal' type. Idempotent + safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'journal' to transactions.transaction_type ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! transactions.transaction_type not found — skipping.\n"; echo "Migration complete.\n"; return; }

    $type = $col['Type'];   // e.g. enum('a','b',...)
    if (!preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        echo "  ! column is not an ENUM ({$type}) — nothing to do.\n"; echo "Migration complete.\n"; return;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $vm[1]);

    if (in_array('journal', $existing, true)) {
        echo "  · 'journal' already present in the ENUM — no enum change.\n";
    } else {
        $all = array_merge($existing, ['journal']);
        $quoted = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
        $notNull = ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM($quoted){$notNull}");
        echo "  + added 'journal' — transaction_type now has " . count($all) . " values.\n";
    }

    // Backfill blank-type rows produced by the local silent truncation, identified
    // by a journal reference. Criteria-based; never touches non-journal rows.
    $fixed = $pdo->exec("
        UPDATE transactions
           SET transaction_type = 'journal'
         WHERE (transaction_type = '' OR transaction_type IS NULL)
           AND (reference_number LIKE 'JRNL-%' OR reference_number LIKE 'REV-%')
    ");
    echo "  + backfilled {$fixed} blank-type journal transaction row(s) to 'journal'.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
