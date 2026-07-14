<?php
/**
 * 2026_07_14_rename_opening_balance_equity_account.php
 * ------------------------------------------------------
 * Renames the take-on/opening-balance equity account (code 3-9999) from
 * "Historical Balancing" to "Opening Balance" on the Chart of Accounts.
 *
 * This is the account credited (or debited) whenever stock is capitalised onto
 * the books without a corresponding cash/AP movement — e.g. manual stock
 * adjustments and, as of this change, initial warehouse quantity assigned at
 * product creation (see core/stock_posting.php: postStockAdjustmentGl()).
 * "Historical Balancing" read as internal jargon; "Opening Balance" is the
 * standard accounting term for the same concept.
 *
 * Criteria-based, not id-based: targets accounts.account_code = '3-9999'
 * wherever it lives in the live chart, never a hard-coded account_id.
 *
 * SAFE TO RE-RUN — no-op once the name is already "Opening Balance".
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: rename Historical Balancing account to Opening Balance...\n";

try {
    $stmt = $pdo->prepare("
        UPDATE accounts
           SET account_name = 'Opening Balance'
         WHERE account_code = '3-9999'
           AND account_name <> 'Opening Balance'
    ");
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo "  + Renamed account 3-9999 to 'Opening Balance' ({$stmt->rowCount()} row updated).\n";
    } else {
        echo "  ~ Account 3-9999 already named 'Opening Balance' (or not found) — skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
