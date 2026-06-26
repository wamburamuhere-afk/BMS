<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: accounts.is_subledger flag + backfill actor sub-accounts...\n";

try {
    // 1. Add the flag column (idempotent). is_subledger = 1 marks a per-actor GL
    //    sub-account (customer/supplier/sub-contractor/employee) that must be hidden
    //    from the chart-top + every account picker and shown only as a subsidiary
    //    ledger entry under its control account. Postings are unaffected — these
    //    accounts are dormant; money already posts to the control accounts.
    $exists = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'is_subledger'")->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE accounts ADD COLUMN is_subledger TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added accounts.is_subledger column.\n";
    } else {
        echo "accounts.is_subledger already exists - skipped.\n";
    }

    // 2. Backfill: flag the per-actor sub-accounts created by core/actor_account.php.
    //    Criteria-based + idempotent: matches the actor code pattern only
    //    (1-1200-CUST-#####, 2-1200-SUP-#####, 2-1200-SUB-#####, 2-1440-EMP-#####);
    //    never the control accounts themselves. No hard-coded ids.
    $before = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_subledger = 1")->fetchColumn();
    $pdo->exec("UPDATE accounts
                   SET is_subledger = 1
                 WHERE is_subledger = 0
                   AND account_code REGEXP '-(CUST|SUP|SUB|EMP)-'");
    $after = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE is_subledger = 1")->fetchColumn();
    echo "Backfill: flagged " . ($after - $before) . " actor sub-accounts as subledger (total now: $after).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
