<?php
/**
 * 2026_06_03_vat_control_accounts.php
 * -----------------------------------
 * VAT 18% control-account foundation (accrual / invoice basis).
 *
 *   1. Ensures two VAT control accounts exist in the Chart of Accounts:
 *        - "Output VAT Payable"     (liability) — VAT charged on SALES invoices
 *        - "Input VAT Recoverable"  (asset)     — VAT paid on RECEIVED invoices
 *      and records their ids in system_settings.
 *
 *   2. Adds idempotency / amount-tracking columns so VAT is posted to the ledger
 *      exactly once and reversed by the exact amount posted:
 *        - invoices.output_vat_posted          DECIMAL(12,2) NULL  (NULL = not posted)
 *        - supplier_invoices.input_vat_posted  DECIMAL(15,2) NULL  (NULL = not posted)
 *
 *   3. Summarises the VAT split onto the received-invoice header (today only the
 *      line items hold it) so reports can read it directly:
 *        - supplier_invoices.subtotal    DECIMAL(15,2) NULL
 *        - supplier_invoices.tax_amount  DECIMAL(15,2) NULL
 *      and backfills both from existing supplier_invoice_items.
 *
 * Idempotent: accounts/columns created only if missing; settings upserted.
 * No transactions wrapped around DDL (MySQL auto-commits DDL).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: VAT control accounts...\n";

try {
    // ── 1. Control accounts ────────────────────────────────────────────────
    $typeIdFor = function (PDO $pdo, string $category): ?int {
        $s = $pdo->prepare("SELECT type_id FROM account_types WHERE category = ? LIMIT 1");
        $s->execute([$category]);
        $v = $s->fetchColumn();
        return $v !== false ? (int)$v : null;
    };
    $liabTypeId  = $typeIdFor($pdo, 'liability');
    $assetTypeId = $typeIdFor($pdo, 'asset');

    $ensureAccount = function (PDO $pdo, string $name, string $code, string $type, string $cf, ?int $typeId) {
        $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE account_name = ? LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
                ->execute([$typeId, (int)$id]);
            return (int)$id;
        }
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id, cash_flow_category,
                          opening_balance, current_balance, status, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, 0, 'active', NOW())")
            ->execute([$code, $name, $type, $typeId, $cf]);
        return (int)$pdo->lastInsertId();
    };

    // cash_flow_category 'operating' (NOT 'cash') keeps these out of the
    // Paid-From cash/bank picker.
    $outId = $ensureAccount($pdo, 'Output VAT Payable', 'VAT-OUT', 'liability', 'operating', $liabTypeId);
    echo "  + Output VAT Payable account id = {$outId} (type_id {$liabTypeId}).\n";

    $inId = $ensureAccount($pdo, 'Input VAT Recoverable', 'VAT-IN', 'asset', 'operating', $assetTypeId);
    echo "  + Input VAT Recoverable account id = {$inId} (type_id {$assetTypeId}).\n";

    $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                             VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $upsert->execute(['default_output_vat_account_id', (string)$outId]);
    $upsert->execute(['default_input_vat_account_id', (string)$inId]);
    echo "  + settings upserted (default output/input VAT account ids).\n";

    // ── 2. Idempotency / posted-amount columns ─────────────────────────────
    $addCol = function (PDO $pdo, string $table, string $col, string $ddl) {
        $exists = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            echo "  + {$table}.{$col} added.\n";
        } else {
            echo "  · {$table}.{$col} already present, skipping.\n";
        }
    };

    $addCol($pdo, 'invoices',          'output_vat_posted', "output_vat_posted DECIMAL(12,2) NULL DEFAULT NULL");
    $addCol($pdo, 'supplier_invoices', 'subtotal',          "subtotal DECIMAL(15,2) NULL DEFAULT NULL");
    $addCol($pdo, 'supplier_invoices', 'tax_amount',        "tax_amount DECIMAL(15,2) NULL DEFAULT NULL");
    $addCol($pdo, 'supplier_invoices', 'input_vat_posted',  "input_vat_posted DECIMAL(15,2) NULL DEFAULT NULL");

    // ── 3. Backfill received-invoice header VAT split from its line items ───
    // tax_amount = Σ item tax; subtotal = Σ item (line_total − tax) = ex-VAT base.
    $pdo->exec("
        UPDATE supplier_invoices si
        JOIN (
            SELECT invoice_id,
                   COALESCE(SUM(tax_amount), 0)                       AS tax_total,
                   COALESCE(SUM(line_total - tax_amount), 0)          AS sub_total
              FROM supplier_invoice_items
          GROUP BY invoice_id
        ) it ON it.invoice_id = si.id
           SET si.tax_amount = it.tax_total,
               si.subtotal   = it.sub_total
         WHERE si.tax_amount IS NULL
    ");
    echo "  + backfilled supplier_invoices subtotal/tax_amount from line items.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
