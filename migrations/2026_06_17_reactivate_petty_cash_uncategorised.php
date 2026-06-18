<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: reactivate Petty Cash – Uncategorised (account #3158)...\n";

try {
    // Account #3158 (6-4000 Petty Cash – Uncategorised) was deactivated while it
    // still had a posted journal entry (entry #75128, voucher_accrual for PV-0004,
    // Dr 100.00). The inactive account drops out of the trial balance query
    // (WHERE a.status='active'), causing a 100.00 Dr/Cr imbalance.
    // Reactivating it restores the ledger to balance.
    $row = $pdo->query("SELECT status FROM accounts WHERE account_id = 3158")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "Account #3158 not found — skipping.\n";
        exit(0);
    }
    if ($row['status'] === 'active') {
        echo "Account #3158 already active — nothing to do.\n";
        exit(0);
    }
    $pdo->exec("UPDATE accounts SET status='active' WHERE account_id = 3158");
    echo "Account #3158 (6-4000 Petty Cash – Uncategorised) reactivated.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
