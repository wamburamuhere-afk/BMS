<?php
/**
 * 2026_07_22_transactions_type_trip.php
 * --------------------------------------
 * Adds 'trip' to transactions.transaction_type so postOutflow($pdo, 'trip', ...)
 * (Employee Trips GL integration, api/manage_trip.php) can post a settlement.
 *
 * Idempotent: parses the CURRENT enum and only appends 'trip' if missing — never
 * re-declares the full list from a hardcoded literal. A hardcoded literal here
 * previously broke production: it was captured from a local dev DB snapshot that
 * was missing 'payroll_accrual'/'sdl_accrual'/'statutory_remittance' (added by
 * 2026_06_06_transactions_type_enum_statutory.php, which had run on production
 * but not locally), so the literal MODIFY silently dropped those values and
 * MySQL's strict mode correctly rejected it as data truncation (error 1265) on
 * any server holding rows with one of those types — aborting the ALTER before
 * anything committed. Read-then-append, as every other transaction_type
 * migration since 2026_06_06 already does, is immune to this drift regardless
 * of which migrations have run on a given server.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'trip' to transactions.transaction_type ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! transactions.transaction_type not found — skipping.\n"; echo "Migration complete.\n"; return; }

    $type = $col['Type'];   // e.g. enum('a','b',...)
    if (!preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        echo "  ! column is not an ENUM ({$type}) — nothing to do.\n"; echo "Migration complete.\n"; return;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $vm[1]);

    if (in_array('trip', $existing, true)) {
        echo "  · 'trip' already present in the ENUM — no enum change.\n";
    } else {
        $all = array_merge($existing, ['trip']);
        $quoted = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
        $notNull = ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM($quoted){$notNull}");
        echo "  + added 'trip' — transaction_type now has " . count($all) . " values.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
