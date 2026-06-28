<?php
/**
 * 2026_06_27_company_code_sequences.php
 * -----------------------------------------------------------------------------
 * Foundation for company-prefixed, gap-free document codes (PREFIX-TYPE-NNNN,
 * e.g. BTL-NIP-0001). See company_code_prefix_plan.md and core/code_generator.php.
 *
 * Creates:
 *   1. code_sequences   — one strictly-increasing counter per entity type.
 *                         Replaces the old MAX(id)+1 / rand() patterns that left
 *                         gaps and produced out-of-order numbers.
 *   2. code_change_log  — audit trail of old->new code conversions (so a document
 *                         re-coded on edit is still findable by its old number).
 *
 * Seeds:
 *   - system_settings.company_code_prefix — auto-derived from company_name
 *     (INSERT IGNORE: never overwrites an admin-set value).
 *   - one row per known entity type in code_sequences (last_value = 0 -> first
 *     code is 0001). Starting at 1 is safe: the new format carries the company
 *     prefix, so it can never collide with any legacy code.
 *
 * Idempotent & DDL-safe (no transactions around CREATE TABLE).
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/code_generator.php';   // deriveCompanyPrefix()
global $pdo;

echo "Starting migration: company_code_sequences...\n";

try {
    // ── 1. code_sequences ──────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS code_sequences (
            sequence_name VARCHAR(30)  NOT NULL,
            last_no       INT UNSIGNED NOT NULL DEFAULT 0,
            digits        TINYINT      NOT NULL DEFAULT 4,
            label         VARCHAR(80)  NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sequence_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table code_sequences ready.\n";

    // ── 2. code_change_log ─────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS code_change_log (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_name VARCHAR(30)  NOT NULL,
            table_name    VARCHAR(64)  NULL,
            record_id     INT          NULL,
            old_code      VARCHAR(64)  NOT NULL,
            new_code      VARCHAR(64)  NOT NULL,
            changed_by    INT          NULL,
            changed_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_old_code (old_code),
            KEY idx_new_code (new_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + Table code_change_log ready.\n";

    // ── 3. Seed company_code_prefix (auto-derived, never overwrite admin) ──
    $companyName = '';
    try {
        $companyName = (string)$pdo->query(
            "SELECT setting_value FROM system_settings WHERE setting_key = 'company_name' LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) { /* settings not ready — derive from blank */ }

    $prefix = deriveCompanyPrefix($companyName);
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group, is_public)
         VALUES ('company_code_prefix', ?, 'company', 1)"
    );
    $ins->execute([$prefix]);
    if ($ins->rowCount() > 0) {
        echo "  + Seeded company_code_prefix = '{$prefix}' (from company name '" . ($companyName ?: '(blank)') . "').\n";
    } else {
        echo "  · company_code_prefix already set — left untouched.\n";
    }

    // ── 4. Seed the known entity sequence types (last_value = 0) ───────────
    // Only entities whose code is auto-generated FROM A FORM. Excludes JRNL
    // (no form), warehouses (manual code), POS shifts, and the hierarchical
    // chart-of-accounts codes.
    $types = [
        'CUST' => 'Customer',            'SUP'  => 'Supplier',
        'SBC'  => 'Sub-contractor',      'NIP'  => 'NIP Product',
        'INV'  => 'Customer Invoice',    'SINV' => 'Supplier Invoice',
        'PO'   => 'Purchase Order',      'GRN'  => 'Goods Received Note',
        'DN'   => 'Delivery Note',       'QT'   => 'Quotation',
        'SO'   => 'Sales Order',         'PAY'  => 'Payment',
        'RCP'  => 'Receipt',             'SPY'  => 'Supplier Payment',
        'ADV'  => 'Customer Advance',    'PV'   => 'Payment Voucher',
        'PR'   => 'Purchase Return',     'ML'   => 'Material List',
        'ADJ'  => 'Stock Adjustment',    'TRF'  => 'Bank Transfer',
        'REV'  => 'Revenue',             'REC'  => 'Reconciliation',
        'LEAD' => 'Lead',                'LPO'  => 'Customer LPO',
        'EMP'  => 'Employee',            'INS'  => 'Inspection',
        'IPC'  => 'IPC',                 'RFQ'  => 'RFQ',
        'DO'   => 'Delivery Order',
    ];
    $seed = $pdo->prepare(
        "INSERT IGNORE INTO code_sequences (sequence_name, last_no, digits, label)
         VALUES (?, 0, 4, ?)"
    );
    $seeded = 0;
    foreach ($types as $code => $label) {
        $seed->execute([$code, $label]);
        $seeded += $seed->rowCount();
    }
    echo "  + Seeded {$seeded} new sequence type(s) (existing ones left untouched).\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
