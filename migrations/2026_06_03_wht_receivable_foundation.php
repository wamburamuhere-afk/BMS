<?php
/**
 * 2026_06_03_wht_receivable_foundation.php
 * ----------------------------------------
 * Plan B foundation — SALES-side Withholding Tax. Your CUSTOMER withholds WHT
 * from what they pay you and remits it to TRA on your behalf; the withheld slice
 * is a TAX CREDIT you reclaim — an ASSET (WHT Receivable), the mirror of the
 * purchase-side WHT Payable (liability) in 2026_06_03_wht_payable_foundation.php.
 *
 * Recognised at CUSTOMER PAYMENT (api/account/record_payment.php). Drift-proof and
 * column-based (Σ payments.wht_posted), like VAT / WHT-Payable — the money-in path
 * does not move account balances, so neither does this.
 *
 *   1. "WHT Receivable" (asset) control account + default_wht_receivable_account_id.
 *   2. payments.wht_rate_id / wht_base / wht_amount / wht_posted.
 *   3. customers.default_wht_rate_id (drives the payment-modal auto-fill).
 *
 * Reuses tax_rates.tax_kind = 'wht' (shared rates). Purely additive — no VAT,
 * WHT-Payable, supplier, or invoice account/column/setting is touched. Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: WHT Receivable foundation (sales-side)...\n";

try {
    // ── 1. WHT Receivable control account (asset) + setting ─────────────────
    $s = $pdo->query("SELECT type_id FROM account_types WHERE category = 'asset' LIMIT 1");
    $assetTypeId = ($v = $s->fetchColumn()) !== false ? (int)$v : null;

    $rid = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'WHT Receivable' LIMIT 1")->fetchColumn();
    if ($rid) {
        $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
            ->execute([$assetTypeId, (int)$rid]);
        $rid = (int)$rid;
        echo "  · WHT Receivable account already exists, id = {$rid}.\n";
    } else {
        // cash_flow_category 'operating' (NOT 'cash') keeps it out of the cash picker.
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                          cash_flow_category, opening_balance, current_balance, status, created_at)
                       VALUES ('WHT-RECV', 'WHT Receivable', 'asset', ?, 'operating', 0, 0, 'active', NOW())")
            ->execute([$assetTypeId]);
        $rid = (int)$pdo->lastInsertId();
        echo "  + WHT Receivable account created, id = {$rid} (type_id {$assetTypeId}).\n";
    }

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                   VALUES ('default_wht_receivable_account_id', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([(string)$rid]);
    echo "  + setting default_wht_receivable_account_id = {$rid}.\n";

    // ── 2. + 3. tracking columns (payments scale is DECIMAL(12,2)) ──────────
    $addCol = function (PDO $pdo, string $table, string $col, string $ddl) {
        if (!$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch()) { echo "  ! {$table} missing — skip {$col}.\n"; return; }
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            echo "  + {$table}.{$col} added.\n";
        } else { echo "  · {$table}.{$col} present, skip.\n"; }
    };
    $addCol($pdo, 'payments',  'wht_rate_id', "wht_rate_id INT NULL DEFAULT NULL");
    $addCol($pdo, 'payments',  'wht_base',    "wht_base DECIMAL(12,2) NULL DEFAULT NULL");
    $addCol($pdo, 'payments',  'wht_amount',  "wht_amount DECIMAL(12,2) NULL DEFAULT NULL");
    $addCol($pdo, 'payments',  'wht_posted',  "wht_posted DECIMAL(12,2) NULL DEFAULT NULL");
    $addCol($pdo, 'customers', 'default_wht_rate_id', "default_wht_rate_id INT NULL DEFAULT NULL");

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
