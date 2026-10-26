<?php
/**
 * migrations/2026_10_26_pos_void_drawer_cash_fix_legacy_db.php
 *
 * Legacy-DB mirror of migrations/tenant/2026_10_26_pos_void_drawer_cash_fix.php:
 * adds the missing refund to each OPEN shift's drawer for sales voided before
 * void_sale.php took back every cash leg (credit deposit, split cash). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/pos_shift_reporting.php';
global $pdo;

echo "Starting migration: POS void drawer cash fix...\n";
try {
    $ready = (bool)$pdo->query("SHOW TABLES LIKE 'cash_register_transactions'")->fetchColumn()
          && (bool)$pdo->query("SHOW TABLES LIKE 'cash_register_shifts'")->fetchColumn()
          && (bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetchColumn();
    if (!$ready) {
        echo "  · POS tables not present — nothing to do.\n";
    } else {
        $added = posFixVoidedSaleDrawerCash($pdo);
        echo $added > 0
            ? "  + $added missing void refund(s) added to open shifts.\n"
            : "  · no open shift is holding cash from a voided sale.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
