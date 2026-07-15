<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add payment_vouchers.reviewed_by...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'reviewed_by'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE payment_vouchers
                    ADD COLUMN reviewed_by INT NULL DEFAULT NULL AFTER prepared_by");
        echo "  + Added payment_vouchers.reviewed_by.\n";
    } else {
        echo "  ~ payment_vouchers.reviewed_by already exists — skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
