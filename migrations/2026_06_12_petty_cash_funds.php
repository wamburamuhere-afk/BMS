<?php
/**
 * 2026_06_12_petty_cash_funds.php
 * -------------------------------
 * Registers which accounts are petty cash FUNDS — the imprest model used by the
 * big systems, where each cash float (per branch / department / custodian) is a
 * separate account deliberately set up. The module records every transaction
 * against the selected fund and tracks each fund's balance independently.
 *
 *   petty_cash_funds(id, account_id UNIQUE, label, status, created_at)
 *
 * Seeds the currently-configured default petty cash account as the first fund,
 * so nothing breaks for single-fund setups. Idempotent, criteria-based.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: petty cash funds registry...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `petty_cash_funds` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `account_id` INT NOT NULL,
            `label`      VARCHAR(120) NULL,
            `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_fund_account` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + petty_cash_funds table ready.\n";

    // Seed the configured default petty cash account as the first fund.
    $defId = (int)getSetting('default_petty_cash_account_id', 0);
    if ($defId > 0) {
        $acc = $pdo->prepare("SELECT account_name FROM accounts WHERE account_id = ?");
        $acc->execute([$defId]);
        $name = $acc->fetchColumn();
        if ($name !== false) {
            $ins = $pdo->prepare("INSERT IGNORE INTO petty_cash_funds (account_id, label, status) VALUES (?, ?, 'active')");
            $ins->execute([$defId, $name ?: 'Main Petty Cash']);
            echo "  + Registered default fund: " . ($name ?: "#$defId") . ($ins->rowCount() ? " (new)" : " (already registered)") . ".\n";
        }
    } else {
        echo "  ~ No default_petty_cash_account_id configured — no fund seeded.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
