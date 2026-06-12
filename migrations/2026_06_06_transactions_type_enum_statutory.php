<?php
/**
 * 2026_06_06_transactions_type_enum_statutory.php
 * -----------------------------------------------
 * Adds the payroll-accrual / SDL-accrual / statutory-remittance transaction types
 * to transactions.transaction_type.
 *
 * Why: core/payment_source.php (postPayrollAccrual, postSdlAccrual) and
 * api/remit_statutory.php write transactions with transaction_type values
 * 'payroll_accrual', 'sdl_accrual' and 'statutory_remittance'. The column is an
 * ENUM that did not include them. On a NON-strict server (typical local WAMP) an
 * out-of-range ENUM is silently coerced to '' and the insert succeeds — so it
 * "works locally". On a STRICT server (production) the insert is REJECTED, so
 * recordGlobalTransaction() returns success=false and the user sees
 * "Failed to post the remittance to the ledger" (and the accrual postings fail
 * silently). Extending the ENUM fixes all three on every server.
 *
 * Idempotent: parses the CURRENT enum and only appends the values that are
 * missing, so it never drops existing values and is safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: extend transactions.transaction_type ENUM (statutory)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! transactions.transaction_type not found — skipping.\n"; echo "Migration complete.\n"; return; }

    $type = $col['Type'];   // e.g. enum('a','b',...)
    // Parse the existing enum members.
    if (!preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        echo "  ! column is not an ENUM ({$type}) — nothing to do.\n"; echo "Migration complete.\n"; return;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $vm[1]);

    $needed  = ['payroll_accrual', 'sdl_accrual', 'statutory_remittance'];
    $missing = array_values(array_filter($needed, fn($v) => !in_array($v, $existing, true)));

    if (!$missing) {
        echo "  · all statutory transaction types already present — no change.\n";
        echo "Migration complete.\n";
        return;
    }

    $all = array_merge($existing, $missing);
    $quoted = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
    $notNull = ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';

    $pdo->exec("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM($quoted){$notNull}");
    echo "  + added: " . implode(', ', $missing) . "\n";
    echo "  + transaction_type now has " . count($all) . " values.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
