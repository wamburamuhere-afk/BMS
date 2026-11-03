<?php
/**
 * migrations/tenant/2026_10_26_pos_void_drawer_cash_fix.php
 *
 * Voiding a credit sale that took a deposit (or a split sale) left the cash in the
 * POS drawer: void_sale.php only took cash back for sales whose payment method was
 * 'cash', so the POS header's "Cash Balance" stayed up by the deposit while the
 * Z-report, which reads the sales, said 0. void_sale.php now reverses every cash
 * leg; this adds the missing refund for sales already voided into OPEN shifts.
 * Closed shifts were reconciled at close and are left alone.
 *
 * Idempotent: the refund is whatever core/pos_shift_reporting.php::
 * posSaleDrawerCashOwed() says is still owed, so a second run adds nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/pos_shift_reporting.php';
global $pdo;

echo "Starting tenant migration: POS void drawer cash fix...\n";
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
