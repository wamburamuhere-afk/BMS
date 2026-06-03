<?php
/**
 * 2026_06_03_wht_payable_foundation.php
 * -------------------------------------
 * Withholding Tax (WHT) foundation — PURCHASE side (you are the withholding
 * agent who deducts WHT from a supplier / sub-contractor payment and remits it
 * to TRA). Mirrors the VAT control-account design
 * (2026_06_03_vat_control_accounts.php) but for a SINGLE liability: WHT Payable.
 *
 *   1. Classifies tax_rates by KIND so ONE "signed-tax" dropdown can tell an
 *      additive VAT rate from a subtractive WHT rate:
 *        - tax_rates.tax_kind ENUM('none','vat','wht')   (default 'vat')
 *      then seeds the resident-service rate (WHT 5%) alongside the existing 2%.
 *
 *   2. Ensures one control account exists + records it in system_settings:
 *        - "WHT Payable" (liability)   ← amount withheld, owed to TRA
 *        - default_wht_payable_account_id
 *
 *   3. Adds idempotency / amount-tracking columns. WHT is RECOGNISED AT PAYMENT,
 *      so wht_posted is set when the payment is recorded and cleared on reversal
 *      — same "NULL = not posted" contract as input_vat_posted, just at a later
 *      lifecycle point. wht_rate_id / wht_base / wht_amount are captured earlier
 *      (on the invoice) so the payment knows exactly what to withhold:
 *        - supplier_invoices.wht_rate_id / wht_base / wht_amount / wht_posted
 *        - supplier_payments.wht_rate_id / wht_base / wht_amount / wht_posted
 *        - suppliers.default_wht_rate_id     (drives the form autofill)
 *
 * Purely ADDITIVE: no VAT account, VAT column, or VAT setting is touched, so the
 * Balance Sheet VAT position is unchanged. Idempotent; no transactions around DDL.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: WHT Payable foundation...\n";

try {
    // ── 1. tax_rates.tax_kind — classify VAT (adds) vs WHT (subtracts) ──────
    if (!$pdo->query("SHOW COLUMNS FROM tax_rates LIKE 'tax_kind'")->fetch()) {
        $pdo->exec("ALTER TABLE tax_rates
                    ADD COLUMN tax_kind ENUM('none','vat','wht') NOT NULL DEFAULT 'vat'
                    AFTER rate_percentage");
        echo "  + tax_rates.tax_kind added (default 'vat').\n";
        // Classify the seed rows ONCE, right after creating the column, so a
        // later admin re-classification is never clobbered on a re-run.
        $pdo->exec("UPDATE tax_rates SET tax_kind = 'none'
                     WHERE rate_percentage = 0 OR rate_name LIKE '%No Tax%'");
        $pdo->exec("UPDATE tax_rates SET tax_kind = 'wht'
                     WHERE rate_name LIKE '%ithholding%' OR rate_name LIKE '%WHT%'");
        echo "  + classified existing tax_rates (none / wht; remainder stays vat).\n";
    } else {
        echo "  · tax_rates.tax_kind already present, skipping classify.\n";
    }

    // Seed the resident service / professional WHT rate (5%) if absent.
    if (!$pdo->query("SELECT rate_id FROM tax_rates WHERE tax_kind = 'wht' AND rate_percentage = 5.00 LIMIT 1")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tax_rates (rate_name, rate_percentage, tax_kind, description, status, created_at)
                       VALUES ('Withholding Tax 5%', 5.00, 'wht', 'WHT on resident professional / service fees', 'active', NOW())")
            ->execute();
        echo "  + seeded 'Withholding Tax 5%' (resident services).\n";
    } else {
        echo "  · WHT 5% rate already present, skipping.\n";
    }
    // Clarify the existing 2% label (per TRA, 2% is supply of goods to Government).
    $pdo->exec("UPDATE tax_rates
                   SET description = 'WHT on supply of goods to Government'
                 WHERE tax_kind = 'wht' AND rate_percentage = 2.00
                   AND (description IS NULL OR description = 'Withholding tax on services')");

    // ── 2. WHT Payable control account + setting ────────────────────────────
    $s = $pdo->query("SELECT type_id FROM account_types WHERE category = 'liability' LIMIT 1");
    $liabTypeId = ($v = $s->fetchColumn()) !== false ? (int)$v : null;

    $stmt = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'WHT Payable' LIMIT 1");
    $whtId = $stmt->fetchColumn();
    if ($whtId) {
        $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
            ->execute([$liabTypeId, (int)$whtId]);
        $whtId = (int)$whtId;
        echo "  · WHT Payable account already exists, id = {$whtId}.\n";
    } else {
        // cash_flow_category 'operating' (NOT 'cash') keeps it out of the
        // Paid-From cash/bank picker — identical to the VAT control accounts.
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                          cash_flow_category, opening_balance, current_balance, status, created_at)
                       VALUES ('WHT-PAY', 'WHT Payable', 'liability', ?, 'operating', 0, 0, 'active', NOW())")
            ->execute([$liabTypeId]);
        $whtId = (int)$pdo->lastInsertId();
        echo "  + WHT Payable account created, id = {$whtId} (type_id {$liabTypeId}).\n";
    }

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                   VALUES ('default_wht_payable_account_id', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([(string)$whtId]);
    echo "  + setting default_wht_payable_account_id = {$whtId}.\n";

    // ── 3. Idempotency / amount-tracking columns ────────────────────────────
    $addCol = function (PDO $pdo, string $table, string $col, string $ddl) {
        if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch()) {
            echo "  ! {$table} not found — skipping {$col}.\n"; return;
        }
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            echo "  + {$table}.{$col} added.\n";
        } else {
            echo "  · {$table}.{$col} already present, skipping.\n";
        }
    };

    foreach (['supplier_invoices', 'supplier_payments'] as $t) {
        $addCol($pdo, $t, 'wht_rate_id', "wht_rate_id INT NULL DEFAULT NULL");
        $addCol($pdo, $t, 'wht_base',    "wht_base DECIMAL(15,2) NULL DEFAULT NULL");
        $addCol($pdo, $t, 'wht_amount',  "wht_amount DECIMAL(15,2) NULL DEFAULT NULL");
        $addCol($pdo, $t, 'wht_posted',  "wht_posted DECIMAL(15,2) NULL DEFAULT NULL");
    }
    $addCol($pdo, 'suppliers', 'default_wht_rate_id', "default_wht_rate_id INT NULL DEFAULT NULL");

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
