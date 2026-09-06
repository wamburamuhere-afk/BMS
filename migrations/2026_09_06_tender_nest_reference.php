<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: tenders.nest_reference...\n";

try {
    // The reference NeST (Tanzania's National e-Procurement System, nest.go.tz)
    // itself assigns once a tender is listed there — distinct from tender_no
    // (the procuring entity's own advert number), so both can be tracked and
    // cross-referenced independently, same distinction the Facile reference
    // system (facile-fms.com) keeps between "Tender Number" and "NeST Reference".
    $exists = $pdo->query("SHOW COLUMNS FROM tenders LIKE 'nest_reference'")->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE tenders ADD COLUMN nest_reference VARCHAR(100) NULL DEFAULT NULL AFTER tender_no");
        echo "  - tenders.nest_reference added.\n";
    } else {
        echo "  - tenders.nest_reference already present, skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
