<?php
/**
 * 2026_05_28_journal_mappings_schema.php
 * ---------------------------------------
 * Phase 4.1 — Journal mapping table + seed defaults.
 *
 * Creates `journal_mappings`, a configurable lookup table that says
 * "when operational event X happens, post a journal entry with these
 * two accounts." This is the single source of truth that the Phase
 * 4.3–4.10 auto-posting hooks will consult before calling
 * postLedgerEntry().
 *
 * Schema:
 *   id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   event_type        VARCHAR(64) NOT NULL UNIQUE      — canonical event slug
 *                                                       (e.g. 'invoice_approved')
 *   description       VARCHAR(255) NOT NULL            — human-readable label
 *                                                       shown in admin UI
 *   debit_account_id  INT NULL  → accounts(account_id) — left side of entry;
 *                                                       NULL until admin sets
 *   credit_account_id INT NULL  → accounts(account_id) — right side of entry
 *   is_active         TINYINT(1) NOT NULL DEFAULT 0    — kill switch; posting
 *                                                       is a no-op while 0
 *   notes             TEXT NULL                        — admin-only notes
 *   created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *   updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE …
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS; seed INSERTs use ON DUPLICATE
 * KEY UPDATE that only touches the `description` column. Admin-set
 * values (account FKs, is_active, notes) are PRESERVED on re-run.
 *
 * Defaults: every event is seeded with NULL account FKs and
 * is_active = 0 ("OFF"). Each Phase 4.3–4.10 sub-step is responsible
 * for the admin choosing the correct accounts and flipping is_active
 * to 1 as part of its rollout.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: journal_mappings schema + seed defaults...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ! accounts table missing — cannot proceed.\n";
        exit(1);
    }

    // ── Create the table ───────────────────────────────────────────────────
    $tableExists = $pdo->query("SHOW TABLES LIKE 'journal_mappings'")->fetch();
    if ($tableExists) {
        echo "  · journal_mappings already exists, skipping CREATE.\n";
    } else {
        $pdo->exec("
            CREATE TABLE `journal_mappings` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_type`        VARCHAR(64) NOT NULL COMMENT 'Canonical event slug (e.g. invoice_approved)',
                `description`       VARCHAR(255) NOT NULL COMMENT 'Human-readable label for admin UI',
                `debit_account_id`  INT NULL COMMENT 'Account_id receiving the debit side; NULL until admin sets',
                `credit_account_id` INT NULL COMMENT 'Account_id receiving the credit side; NULL until admin sets',
                `is_active`         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kill switch: posting is a no-op while 0',
                `notes`             TEXT NULL COMMENT 'Admin-only notes',
                `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_jm_event_type` (`event_type`),
                KEY `ix_jm_debit_account` (`debit_account_id`),
                KEY `ix_jm_credit_account` (`credit_account_id`),
                KEY `ix_jm_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='Phase 4 auto-posting: event_type → (debit, credit) account mapping'
        ");
        echo "  + created table journal_mappings.\n";
    }

    // ── Foreign keys (wrapped — types must match accounts.account_id) ─────
    foreach ([
        ['fk_jm_debit_acct',  'debit_account_id'],
        ['fk_jm_credit_acct', 'credit_account_id'],
    ] as [$fkName, $col]) {
        try {
            $existsFk = $pdo->query("
                SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'journal_mappings'
                   AND CONSTRAINT_NAME = '{$fkName}'
            ")->fetchColumn();
            if (!$existsFk) {
                $pdo->exec("ALTER TABLE `journal_mappings`
                            ADD CONSTRAINT `{$fkName}`
                            FOREIGN KEY (`{$col}`) REFERENCES `accounts`(`account_id`)
                            ON DELETE RESTRICT ON UPDATE CASCADE");
                echo "  + added FK {$fkName} on {$col}.\n";
            } else {
                echo "  · FK {$fkName} already present, skipping.\n";
            }
        } catch (PDOException $e) {
            echo "  ! Could not add FK {$fkName} (non-fatal): " . $e->getMessage() . "\n";
        }
    }

    // ── Seed canonical event_type rows ────────────────────────────────────
    //
    // ON DUPLICATE KEY UPDATE only refreshes the human-readable description.
    // Admin-set columns (debit_account_id, credit_account_id, is_active,
    // notes) are PRESERVED on re-run.
    $seed = [
        ['invoice_approved',  'Sales invoice approved — Dr Accounts Receivable / Cr Revenue'],
        ['payment_received',  'Customer payment received — Dr Cash / Cr Accounts Receivable'],
        ['expense_paid',      'Expense marked paid — Dr Expense / Cr Cash'],
        ['payroll_paid',      'Payroll approved/paid — Dr Salaries Expense / Cr Cash'],
        ['grn_approved',      'Goods Received Note approved — Dr Inventory / Cr Accounts Payable'],
        ['supplier_payment',  'Supplier payment recorded — Dr Accounts Payable / Cr Cash'],
        ['asset_purchased',   'Fixed asset purchased — Dr PP&E / Cr Cash (or AP)'],
        ['depreciation_run',  'Depreciation run — Dr Depreciation Expense / Cr Accumulated Depreciation'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO `journal_mappings` (`event_type`, `description`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
    ");
    $inserted = 0; $updated = 0;
    foreach ($seed as [$event, $desc]) {
        $stmt->execute([$event, $desc]);
        if ($stmt->rowCount() === 1)      $inserted++;
        elseif ($stmt->rowCount() === 2)  $updated++;
    }
    echo "  + seed: {$inserted} inserted, {$updated} description(s) refreshed, " .
         (count($seed) - $inserted - $updated) . " unchanged.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
