<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add entrance_fee_tzs and entrance_fee_usd to tenders...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM tenders LIKE 'entrance_fee_tzs'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE tenders ADD COLUMN entrance_fee_tzs DECIMAL(15,2) NULL DEFAULT NULL AFTER tender_amount_usd");
        echo "Added entrance_fee_tzs.\n";
    } else {
        echo "entrance_fee_tzs already exists, skipping.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM tenders LIKE 'entrance_fee_usd'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE tenders ADD COLUMN entrance_fee_usd DECIMAL(15,2) NULL DEFAULT NULL AFTER entrance_fee_tzs");
        echo "Added entrance_fee_usd.\n";
    } else {
        echo "entrance_fee_usd already exists, skipping.\n";
    }

    // Back-populate: for records not yet submitted, tender_amount_tzs/usd IS the entrance fee
    $pdo->exec("
        UPDATE tenders
        SET entrance_fee_tzs = COALESCE(entrance_fee_tzs, tender_amount_tzs),
            entrance_fee_usd = COALESCE(entrance_fee_usd, tender_amount_usd)
        WHERE status IN ('PENDING', 'APPROVED', 'INVITATION')
    ");
    echo "Back-populated entrance fees for pre-submission records.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
