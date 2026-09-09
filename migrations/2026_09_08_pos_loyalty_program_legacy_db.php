<?php
/**
 * migrations/2026_09_08_pos_loyalty_program_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_07_pos_loyalty_program.php (Phase 11)
 * onto the LEGACY / non-tenant database. Found while scouting for other
 * instances of the same bug class after the two 2026-09-08 incidents (see
 * 2026_09_08_pos_price_groups_legacy_db.php): `api/pos/search_customers.php`
 * unconditionally selects `loyalty_points_balance` on every customer search
 * at the POS till — a far more frequently-hit path than either of today's
 * two incidents — so a legacy database missing this column throws the exact
 * same class of fatal PDOException on ordinary POS use, not a hypothetical
 * future one. Dated 09-08 (not 09-07) because that's when this file was
 * actually written; it mirrors a migration that shipped the day before.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS loyalty program on the legacy database (Phase 11)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points_balance'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM customers LIKE 'current_balance'")->fetch();
        $position = $anchor ? " AFTER current_balance" : "";
        $pdo->exec("ALTER TABLE customers ADD COLUMN loyalty_points_balance INT NOT NULL DEFAULT 0$position");
        echo "  + customers.loyalty_points_balance added" . ($position ? "" : " (anchor column 'current_balance' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · customers.loyalty_points_balance already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_loyalty_transactions (
            loyalty_txn_id INT NOT NULL AUTO_INCREMENT,
            customer_id INT NOT NULL,
            sale_id INT DEFAULT NULL,
            txn_type ENUM('earn','redeem','earn_reversal','redeem_reversal') NOT NULL,
            points INT NOT NULL COMMENT 'always positive; txn_type gives direction',
            balance_after INT NOT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (loyalty_txn_id),
            KEY idx_customer_id (customer_id),
            KEY idx_sale_id (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + customer_loyalty_transactions table ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
