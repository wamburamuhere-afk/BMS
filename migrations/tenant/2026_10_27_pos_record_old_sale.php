<?php
/**
 * migrations/tenant/2026_10_27_pos_record_old_sale.php
 *
 * "Record Old Sale" (Rekodi Mauzo ya Zamani) foundation — see core/pos_old_sales.php.
 *   - pos_sales.is_past_entry            : 1 = typed in later for an earlier day
 *   - cash_register_shifts.is_past_entry : 1 = back-dated shift created for such sales
 *   - permission 'pos_past_sales'        : admins have it; other roles are granted it
 *                                          in Roles & Permissions
 * Idempotent: columns and index are checked before adding; the permission uses INSERT IGNORE.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: POS record old sale...\n";
try {
    if ((bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetchColumn()) {
        if (!$pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'is_past_entry'")->fetch()) {
            $pdo->exec("ALTER TABLE pos_sales ADD COLUMN is_past_entry TINYINT(1) NOT NULL DEFAULT 0");
            echo "  + pos_sales.is_past_entry added.\n";
        }
        if (!$pdo->query("SHOW INDEX FROM pos_sales WHERE Key_name = 'idx_pos_sales_past_entry'")->fetch()) {
            $pdo->exec("ALTER TABLE pos_sales ADD INDEX idx_pos_sales_past_entry (is_past_entry)");
            echo "  + idx_pos_sales_past_entry added.\n";
        }
    }
    if ((bool)$pdo->query("SHOW TABLES LIKE 'cash_register_shifts'")->fetchColumn()
        && !$pdo->query("SHOW COLUMNS FROM cash_register_shifts LIKE 'is_past_entry'")->fetch()) {
        $pdo->exec("ALTER TABLE cash_register_shifts ADD COLUMN is_past_entry TINYINT(1) NOT NULL DEFAULT 0");
        echo "  + cash_register_shifts.is_past_entry added.\n";
    }
    if ((bool)$pdo->query("SHOW TABLES LIKE 'permissions'")->fetchColumn()) {
        $pdo->exec("
            INSERT IGNORE INTO permissions (permission_name, page_key, page_name, description, module_name)
            SELECT '', 'pos_past_sales', 'POS Record Old Sale',
                   'Record a sale made on an earlier day (up to the POS Settings limit) from the POS terminal',
                   'Sales'
              FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE page_key = 'pos_past_sales')
        ");
        echo "  pos_past_sales permission row ensured.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
