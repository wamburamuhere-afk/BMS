<?php
/**
 * 2026_05_29_dn_partially_delivered.php
 * ----------------------------------------
 * Adds 'partially_delivered' to deliveries.status ENUM so that a DN
 * can be marked partially delivered when a GRN only covers some items.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: deliveries.status — add partially_delivered...\n";

try {
    $row = $pdo->query("SHOW COLUMNS FROM `deliveries` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "  ! deliveries.status column not found — skipping.\n";
        exit(0);
    }

    if (stripos($row['Type'], 'partially_delivered') !== false) {
        echo "  · partially_delivered already in ENUM — skipping.\n";
    } else {
        $newType = preg_replace_callback(
            "/enum\\((.*)\\)/i",
            function ($m) {
                return "enum(" . $m[1] . ",'partially_delivered')";
            },
            $row['Type']
        );
        $null    = ($row['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
        $default = $row['Default'] !== null ? " DEFAULT " . $pdo->quote($row['Default']) : '';
        $pdo->exec("ALTER TABLE `deliveries` MODIFY COLUMN `status` {$newType} {$null}{$default}");
        echo "  + added partially_delivered to deliveries.status ENUM.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
