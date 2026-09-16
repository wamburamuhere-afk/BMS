<?php
/**
 * migrations/tenant/2026_09_16_pos_sales_due_date.php
 *
 * POS credit sales (pos_sales.payment_method = 'credit') had no due date at
 * all — nothing to base an aging/receivables view or a "coming due" reminder
 * on. Part of the Simple POS credit-receivables feature
 * (pos_credit_receivables_plan.md, Phase 0).
 *
 * Purely additive, nullable column + a supporting index. NULL means
 * "no due date set" — every existing sale, and every Advanced-mode sale
 * going forward (that flow never sets it), is completely unaffected.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add pos_sales.due_date...\n";

try {
    $has = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'due_date'")->fetch();
    if ($has) {
        echo "  pos_sales.due_date already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN due_date DATE NULL AFTER payment_date");
        echo "  + Added pos_sales.due_date (DATE NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM pos_sales WHERE Key_name = 'idx_pos_sales_due_date'")->fetch();
    if ($hasIdx) {
        echo "  Index idx_pos_sales_due_date already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_sales ADD INDEX idx_pos_sales_due_date (due_date)");
        echo "  + Added index idx_pos_sales_due_date.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
