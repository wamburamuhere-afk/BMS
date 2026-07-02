<?php
/**
 * Convert every MyISAM table to InnoDB.
 *
 * MySQL transactions (beginTransaction/commit/rollBack) are silently IGNORED
 * on MyISAM tables — a rollback leaves the writes in place. 80 tables
 * (payroll, payment_vouchers, bank_transactions, pos_payments, product_stocks,
 * loans, …) were still MyISAM, so every "atomic" money/stock write touching
 * them had no rollback protection at all. InnoDB is required for the
 * transaction-safety work (fix/tx-* branches) to mean anything.
 *
 * Criteria-based and idempotent: converts whatever MyISAM tables exist right
 * now; a re-run finds none and does nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: convert MyISAM tables to InnoDB...\n";

try {
    $tables = $pdo->query("
        SELECT TABLE_NAME
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE   = 'BASE TABLE'
           AND ENGINE       = 'MyISAM'
         ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No MyISAM tables found — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    echo count($tables) . " MyISAM table(s) to convert.\n";

    $converted = 0;
    foreach ($tables as $t) {
        // Identifier comes from information_schema, but quote it anyway.
        // ROW_FORMAT=DYNAMIC (InnoDB's default) is stated explicitly because
        // MyISAM tables created with row_format=FIXED fail the conversion
        // under innodb_strict_mode with error 1031 otherwise.
        $safe = str_replace('`', '', $t);
        $pdo->exec("ALTER TABLE `$safe` ROW_FORMAT=DYNAMIC, ENGINE=InnoDB");
        $converted++;
        echo "  ✓ $safe → InnoDB\n";
    }

    // Verify nothing was left behind
    $left = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
           AND ENGINE = 'MyISAM'
    ")->fetchColumn();

    if ((int)$left > 0) {
        echo "Migration failed: $left MyISAM table(s) remain after conversion.\n";
        exit(1);
    }

    echo "Converted $converted table(s). All base tables are now InnoDB.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
